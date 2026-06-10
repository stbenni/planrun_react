# Backend: providers, utils, validators — справочник функций

Контекст: providers — интеграции с фитнес-сервисами (импорт тренировок через OAuth/webhook), utils — парсеры файлов тренировок (GPX/TCX/FIT), validators — валидация входных данных плана. Все 6 провайдеров реализуют интерфейс `WorkoutImportProvider` и регистрируются в `IntegrationsController::PROVIDERS` и `api/oauth_callback.php`. Канонический формат тренировки (выход `fetchWorkouts`): `activity_type, start_time, end_time, duration_minutes, duration_seconds, distance_km, avg_pace, avg_heart_rate, max_heart_rate, elevation_gain, external_id, timeline[, laps]`. Все токены хранятся в таблице БД `integration_tokens` (ключ user_id+provider).

---

## `planrun-backend/providers/WorkoutImportProvider.php` (42 строки)
Общий интерфейс провайдера импорта тренировок. Только контракт, без реализации.

### interface WorkoutImportProvider — L5
Контракт из 7 методов; реализуют CorosProvider, GarminProvider, HuaweiHealthProvider, PolarProvider, StravaProvider, SuuntoProvider.

#### `getProviderId(): string` — L9
ID провайдера ('huawei', 'garmin', 'strava', 'polar', 'coros', 'suunto').

#### `getOAuthUrl(string $state): ?string` — L14
URL для OAuth-авторизации; null если провайдер не сконфигурирован (нет creds в .env).

#### `exchangeCodeForTokens(string $code, string $state): array` — L20
Обмен authorization code на токены, сохранение в `integration_tokens`. Возвращает `['access_token','refresh_token','expires_at']`.

#### `refreshToken(int $userId): bool` — L25
Обновление access_token по refresh_token.

#### `fetchWorkouts(int $userId, string $startDate, string $endDate): array` — L31
Получить тренировки за период в каноническом формате.

#### `isConnected(int $userId): bool` — L36
Подключён ли провайдер у пользователя (есть ли строка в `integration_tokens`).

#### `disconnect(int $userId): void` — L41
Отвязка провайдера (удаление токенов).

---

## `planrun-backend/providers/CorosProvider.php` (489 строк)
COROS Training Hub / Partner API: OAuth 2.0 (опционально PKCE) и импорт активностей. Полностью конфигурируется через .env (URL'ы, путь, метод, имя заголовка токена) — заготовка под будущее одобрение заявки COROS. Сейчас COROS_* creds в .env отсутствуют → OAuth недоступен, `fetchWorkouts` возвращает []; используется только из `api/coros_workout_push.php` (push-эндпоинт), где фактически тоже инертен без COROS_API_BASE.

### class CorosProvider — L11
Реализация WorkoutImportProvider для COROS.

#### `__construct($db)` — L30
Сохраняет mysqli-подключение; читает из .env: COROS_CLIENT_ID/SECRET, REDIRECT_URI, OAUTH_AUTH_URL/TOKEN_URL, SCOPES, USE_PKCE, TOKEN_CLIENT_AUTH (body|basic), API_BASE, ACTIVITY_FETCH_PATH/METHOD, API_ACCESS_HEADER/PREFIX.

#### `getProviderId()` — L47
Возвращает 'coros'.

#### `base64UrlEncode(string $bin)` — L51 (private static)
URL-safe base64 без паддинга (для PKCE verifier/challenge).

#### `pkceSessionKey(string $state)` — L55 (private)
Ключ `$_SESSION` для PKCE verifier: `planrun_coros_pkce_` + sha256(state).

#### `getOAuthUrl(string $state)` — L59
Строит URL авторизации COROS; null если нет clientId/redirectUri/authUrl. При PKCE генерирует verifier (в `$_SESSION`) и S256 challenge. Побочный эффект: запись в сессию.

#### `exchangeCodeForTokens(string $code, string $state)` — L83
Требует залогиненного пользователя (`getCurrentUserId()`). POST на tokenUrl (cURL, 30s); client auth в body или Basic по конфигу; при PKCE достаёт verifier из сессии (исключение если истёк). Извлекает external user id, сохраняет токены через `saveTokens()` (БД integration_tokens). Бросает Exception с текстом ошибки API.

#### `extractExternalUserId(string $accessToken, array $tokenResponse)` — L161 (private)
Ищет ID пользователя COROS в полях ответа токена (openId/user_id/sub и др.), иначе декодирует payload JWT access_token. null если не нашёл.

#### `refreshToken(int $userId)` — L186
POST grant_type=refresh_token на tokenUrl; при успехе пересохраняет токены (новый refresh либо старый). false при пустом tokenUrl/отсутствии refresh_token/ошибке HTTP.

#### `fetchWorkouts(int $userId, string $startDate, string $endDate)` — L236
Если COROS_API_BASE/FETCH_PATH пусты — Logger::warning и []. Авто-refresh токена за 60с до истечения. GET или POST (по конфигу) на apiBase/path с query startDate/endDate + COROS_ACTIVITY_EXTRA_QUERY_JSON; токен в настраиваемом заголовке. Маппит каждый элемент через `mapCorosActivityToWorkout()`.

#### `extractActivityList($json)` — L318 (private)
Достаёт список активностей из обёрток ответа (data/activities/activityList/records/list/items/result) либо принимает «голый» массив.

#### `mapCorosActivityToWorkout(array $a)` — L339 (private)
Маппинг в канонический формат: start из startTimestamp/startTime/startTimeGMT (эвристика мс→с при значении >2e10), duration из 5 вариантов поля (эвристика мс→с при >864000), distance с эвристикой метры/км (>200 → делим на 1000), HR, набор высоты. external_id = `coros_<id|startTime>`. timeline всегда null. null если нет времени старта.

#### `mapCorosSportType(string $t)` — L417 (private)
Маппинг по подстроке (RUN/WALK/HIKE|TRAIL/CYCLE|BIKE/SWIM) → наш тип; default и пустая строка → 'running'.

#### `paceFromKmAndMinutes(float $km, int $minutes): ?string` — L437 (private)
Темп `м:сс` из км и минут; null при km<=0.

#### `isConnected(int $userId)` — L451
true если есть строка токена в БД.

#### `disconnect(int $userId)` — L455
DELETE из `integration_tokens` по user_id+provider.

#### `getTokenRow(int $userId)` — L463 (private)
SELECT access/refresh/expires/external_athlete_id из `integration_tokens`.

#### `saveTokens(...)` — L473 (private)
INSERT ... ON DUPLICATE KEY UPDATE в `integration_tokens` (external_athlete_id через COALESCE — не затирается null'ом).

---

## `planrun-backend/providers/GarminProvider.php` (409 строк)
Garmin Connect Developer Program: OAuth 2.0 с обязательным PKCE + Wellness REST. Структурно почти копия CorosProvider (тот же скелет PKCE/токены/маппер). Зарегистрирован в PROVIDERS-карте, но в .env нет ни одной GARMIN_* переменной → `getOAuthUrl()` всегда null, интеграция не активируема (ожидает одобрения Garmin). Webhook-эндпоинта для Garmin нет.

### class GarminProvider — L10
Реализация WorkoutImportProvider для Garmin.

#### `__construct($db)` — L24
Читает GARMIN_CLIENT_ID/SECRET, REDIRECT_URI из .env; authUrl/tokenUrl/wellnessBase/activityFetchPath имеют зашитые дефолты (connect.garmin.com, diauth.garmin.com, apis.garmin.com/wellness-api/rest, activityDetailsSummary).

#### `getProviderId()` — L37
Возвращает 'garmin'.

#### `base64UrlEncode(string $bin)` — L41 (private static)
Дубликат CorosProvider::base64UrlEncode.

#### `pkceSessionKey(string $state)` — L45 (private)
Ключ сессии `planrun_garmin_pkce_` + sha256(state).

#### `getOAuthUrl(string $state)` — L49
PKCE обязателен (в отличие от COROS): всегда генерирует verifier (мин. 43 символа) в `$_SESSION` и S256 challenge. null без clientId/redirectUri.

#### `exchangeCodeForTokens(string $code, string $state)` — L74
POST на tokenUrl c client_id+client_secret+code_verifier в body (без Basic-варианта). Извлекает Garmin userId из JWT, сохраняет в БД. Exception при просроченной сессии PKCE или ошибке токена.

#### `extractUserIdFromAccessToken(string $jwt)` — L129 (private)
Декодирует payload JWT, ищет userId/user_id/sub/customerId. Дубликат логики Coros::extractExternalUserId (без поиска в ответе токена).

#### `refreshToken(int $userId)` — L152
POST grant_type=refresh_token с creds в body; пересохраняет токены, обновляет external_athlete_id из JWT. false при ошибке.

#### `fetchWorkouts(int $userId, string $startDate, string $endDate)` — L192
Авто-refresh за 60с. GET/POST wellnessBase/activityFetchPath с `uploadStartTimeInSeconds`/`uploadEndTimeInSeconds` (UTC-границы суток) — отличие от COROS (там startDate/endDate строками). Bearer-токен. Маппинг через `mapGarminActivityToWorkout()`.

#### `extractActivityList($json)` — L265 (private)
Те же обёртки, но Garmin-ключи (activityDetailsSummaryList/activitySummaryList/...); дополнительно умеет `array_values()` для ассоц-обёртки.

#### `mapGarminActivityToWorkout(array $a)` — L289 (private)
Канонический формат из полей Garmin Wellness (startTimeInSeconds, durationInSeconds, distanceInMeters, averageHeartRateInBeatsPerMinute и др.). external_id = `garmin_<id|startTime>`, timeline = null.

#### `mapGarminActivityType(string $t)` — L337 (private)
Подстрочный маппинг RUN/CYCL|BIKE/SWIM/WALK/HIKE → тип; default 'running'.

#### `paceFromKmAndMinutes(float $km, int $minutes): string` — L357 (private)
Дубликат Coros-версии, но с декларацией возврата `string` при `return null` в первой ветке — потенциальный TypeError при km<=0 (фактически защищён проверкой у вызывающего).

#### `isConnected(int $userId)` — L371
Как у Coros.

#### `disconnect(int $userId)` — L375
DELETE из `integration_tokens`.

#### `getTokenRow(int $userId)` — L383 (private)
Идентичен Coros::getTokenRow.

#### `saveTokens(...)` — L393 (private)
Идентичен Coros::saveTokens (UPSERT в `integration_tokens`).

---

## `planrun-backend/providers/HuaweiHealthProvider.php` (348 строк)
Huawei Health Kit REST API: OAuth через oauth-login.cloud.huawei.com, данные через health-api.cloud.huawei.com (`sampleSet:polymerize`). Зарегистрирован в PROVIDERS-карте, но в .env задан только HUAWEI_HEALTH_REDIRECT_URI (нет client_id/secret) → `getOAuthUrl()` возвращает null, интеграция неактивна. Самый старый/простой провайдер: отклонение от канона — в выходе `fetchWorkouts` НЕТ ключей avg_heart_rate/max_heart_rate/elevation_gain/timeline.

### class HuaweiHealthProvider — L7
Реализация WorkoutImportProvider для Huawei Health.

#### `__construct($db)` — L14
Читает HUAWEI_HEALTH_CLIENT_ID/SECRET/REDIRECT_URI/SCOPES; redirect_uri нормализуется (добавляется ?provider=huawei). Дефолтные scopes: activity.read + historydata.open.month.

#### `getProviderId()` — L22
Возвращает 'huawei'.

#### `getOAuthUrl(string $state)` — L26
URL oauth-login.cloud.huawei.com/oauth2/v3/authorize с access_type=offline. null если не заданы clientId/clientSecret/redirectUri (единственный провайдер, требующий clientSecret уже на этом шаге).

#### `exchangeCodeForTokens(string $code, string $state)` — L41
POST на /oauth2/v3/token (cURL 30s). При ошибке — `logWarning()` с деталями + Exception через `buildErrorMessage()`. Сохраняет токены в `integration_tokens` (без external_athlete_id — единственный OAuth-провайдер без него).

#### `refreshToken(int $userId)` — L88
POST grant_type=refresh_token на тот же endpoint; при ошибке логирует и возвращает false.

#### `fetchWorkouts(int $userId, string $startDate, string $endDate)` — L126
Авто-refresh за 60с, но в отличие от остальных БРОСАЕТ Exception при неудаче refresh/недоступности API/не-200/битом JSON (другие молча возвращают []). POST на `healthkit/v1/sampleSet:polymerize` с dataTypeName `com.huawei.continuous.activity.summary`, время в мс. Маппинг через `mapHuaweiResponseToWorkouts()`.

#### `mapHuaweiResponseToWorkouts(array $data)` — L192 (private)
Обходит `sampleSets[].samples[]`: время из startTime/endTime (мс), из fieldValues берёт distance (м→км), duration/exercise_duration (с), activity_type (числовой код). Выход без HR/elevation/timeline; external_id = `$s['id']` либо `startTime-distance` (без префикса 'huawei_' — отклонение от схемы других провайдеров).

#### `paceFromDistanceAndDuration(?float $distanceKm, ?int $durationSeconds, string $activityType)` — L237 (private)
Темп из секунд (точнее, чем минутные версии у Coros/Garmin/Polar); null для не-бег/ходьба/хайк.

#### `mapActivityType($value)` — L248 (private)
Числовой код Huawei 1–6 → тип; default 'running'.

#### `normalizeRedirectUri(string $uri)` — L256 (private)
Разбирает URI через parse_url, принудительно добавляет/заменяет query-параметр `provider=huawei` и пересобирает URI (уникален для этого провайдера).

#### `buildErrorMessage(string $fallback, int $httpCode, $response, string $curlError = '')` — L286 (private)
Человекочитаемое сообщение об ошибке: HTTP-код + error_description/error/message из JSON либо сырой ответ (≤300 символов).

#### `logWarning(string $message, array $context = [])` — L307 (private)
Ленивая загрузка config/Logger.php и `\Logger::warning()`; молча выходит, если файла нет.

#### `isConnected(int $userId)` — L315
Стандартно (строка в `integration_tokens`).

#### `disconnect(int $userId)` — L319
DELETE из `integration_tokens`.

#### `getTokenRow(int $userId)` — L327 (private)
SELECT без external_athlete_id (отличие от Coros/Garmin/Suunto).

#### `saveTokens(...)` — L337 (private)
UPSERT без external_athlete_id.

---

## `planrun-backend/providers/PolarProvider.php` (596 строк)
Polar AccessLink API v3: OAuth 2.0 (Basic auth на token endpoint), `/v3/exercises` с route+samples, плюс блок партнёрского webhook-API (регистрация/проверка подписки EXERCISE, верификация HMAC-подписи). Активно используется: oauth_callback, api/polar_webhook.php, scripts/polar_register_webhook.php, polar_webhook_health.php. Отклонения от канона: токены Polar бессрочные → `refreshToken()` — заглушка; после OAuth обязательна регистрация пользователя в AccessLink (`registerUser`).

### class PolarProvider — L9
Реализация WorkoutImportProvider + webhook-методы Polar.

#### `__construct($db)` — L16
Читает POLAR_CLIENT_ID/SECRET/REDIRECT_URI; baseUrl зашит: https://www.polaraccesslink.com.

#### `getProviderId()` — L23
Возвращает 'polar'.

#### `getOAuthUrl(string $state)` — L27
URL flow.polar.com/oauth2/authorization c явным scope `accesslink.read_all`. null без clientId/redirectUri.

#### `exchangeCodeForTokens(string $code, string $state)` — L42
POST на polarremote.com/v2/oauth2/token с Basic auth (client_id:client_secret) — отличие от Coros/Garmin (creds в body). Получает `x_user_id` (Polar user id), вызывает `registerUser()`, сохраняет токен (refresh_token/expires_at = NULL). Exception с расшифровкой ошибки.

#### `registerUser(int $userId, string $accessToken)` — L94 (private)
POST /v3/users с member-id = наш userId — обязательная регистрация пользователя в AccessLink. 409 (уже зарегистрирован) считается нормой; остальные ошибки игнорируются молча.

#### `refreshToken(int $userId)` — L116
Заглушка: токены Polar не истекают, просто возвращает `isConnected()`. Отклонение от интерфейсной семантики.

#### `fetchWorkouts(int $userId, string $startDate, string $endDate)` — L120
GET /v3/exercises?route=true&samples=true (API не принимает период!), фильтрация по датам выполняется на нашей стороне по start_time. Маппинг через `mapExerciseToWorkout()`. [] при ошибке HTTP.

#### `mapExerciseToWorkout(array $ex)` — L158 (private)
Канонический формат: duration из ISO 8601 (`parseIsoDuration`), distance м→км, HR из объекта heart_rate, elevation_gain суммируется из положительных перепадов altitude в route, timeline через `buildTimeline()`. external_id = `polar_<id|...>`. Использует локальный date() (не gmdate, в отличие от Coros/Garmin/Suunto).

#### `parseIsoDuration(string $iso)` — L211 (private)
Секунды из ISO-8601 длительности `PT#H#M#S`.

#### `mapSportType(string $sport)` — L219 (private)
Подстрочный маппинг (run/cycl|bike/swim/walk/hike), default 'running' — дубликат паттерна остальных провайдеров.

#### `paceFromKmAndMinutes(float $km, int $minutes): string` — L229 (private)
Дубликат Coros/Garmin-версии (тоже `return null` при декларации string).

#### `buildTimeline(array $ex, int $startTs)` — L238 (private)
Склейка HR-сэмплов (sample-type '1', шаг recording-rate) и route-точек (altitude/lat/lng по секундам от старта) в общий ряд; даунсэмплинг до ~500 точек. pace/distance/cadence в точках всегда null.

#### `isConnected(int $userId)` — L295
Стандартно.

#### `disconnect(int $userId)` — L299
Сначала DELETE /v3/users/{external_athlete_id} в AccessLink (HTTP-вызов; единственный провайдер с удалением на стороне сервиса), затем DELETE из `integration_tokens`.

#### `getTokenRow(int $userId)` — L317 (private)
SELECT только access_token + external_athlete_id (refresh/expires не нужны).

#### `saveTokens(int $userId, string $accessToken, ?string $polarUserId)` — L326 (private)
UPSERT c refresh_token=NULL, expires_at=NULL.

#### `getWebhookSecretStoragePath()` — L339 (private)
Путь к файлу `storage/polar_webhook_secret.txt`.

#### `loadWebhookSignatureSecret(): string` — L346 (public)
Секрет подписи webhook: сначала POLAR_WEBHOOK_SIGNATURE_SECRET из .env, потом файл storage. Используется api/polar_webhook.php.

#### `saveWebhookSignatureSecret(string $secret): bool` — L361 (public)
Сохраняет секрет в файл storage (mkdir 0750 + file_put_contents LOCK_EX). Вызывается только изнутри `ensureWebhookSubscription()` — мог бы быть private.

#### `verifyWebhookSignature(string $rawBody, ?string $signatureHex): bool` — L379 (public)
Проверка заголовка Polar-Webhook-Signature: HMAC-SHA256 тела, сравнение hash_equals. Используется api/polar_webhook.php.

#### `partnerApiRequest(string $method, string $path, ?string $jsonBody = null)` — L392 (private)
Универсальный cURL-запрос к AccessLink c Basic auth приложения (client_id:client_secret); возвращает `{ok, httpCode, body, error}`.

#### `getPartnerWebhookData(): ?array` — L432 (public)
GET /v3/webhooks; нормализует ответ (объект или массив объектов) к одному вебхуку `{id,url,events}`. Вызывается только из `ensureWebhookSubscription()`.

#### `deletePartnerWebhook(string $webhookId): bool` — L458 (public)
DELETE /v3/webhooks/{id}; true при 200/204. Вызывается только из `ensureWebhookSubscription()`.

#### `createPartnerWebhook(string $callbackUrl): array` — L471 (public)
POST /v3/webhooks (events: EXERCISE); возвращает id и signature_secret_key. Вызывается только из `ensureWebhookSubscription()`.

#### `normalizeWebhookEvents($events)` — L496 (private static)
Нормализация поля events (строка/массив) в массив UPPERCASE-строк.

#### `ensureWebhookSubscription(): array` — L520 (public)
Идемпотентная регистрация webhook на POLAR_WEBHOOK_CALLBACK_URL: если существующий совпадает по URL+EXERCISE — ничего; иначе удалить и создать заново, сохранить signature secret в storage. 409/WebhookExistException трактуется как успех. Используется oauth_callback (косвенно скриптами), polar_register_webhook.php, polar_webhook_health.php.

#### `fetchSingleExerciseByUrl(int $userId, string $exerciseUrl): ?array` — L565 (public)
Загрузка одной тренировки по URL из webhook-события (добавляет route=true&samples=true), маппинг через `mapExerciseToWorkout()`. Используется api/polar_webhook.php.

---

## `planrun-backend/providers/StravaProvider.php` (861 строка)
Strava API v3 — самый развитый провайдер: OAuth, постраничный импорт /athlete/activities, обогащение деталями/стримами/кругами, webhook push_subscriptions, health-check интеграции, опциональный SOCKS5/HTTP-прокси (STRAVA_PROXY). Используется: oauth_callback, api/strava_webhook.php, scripts/strava_register_webhook.php, strava_daily_health_check.php, process_strava_webhook_retries.php.

### class StravaProvider — L8
Реализация WorkoutImportProvider + webhook + health-check.

#### `__construct($db)` — L16
Читает STRAVA_CLIENT_ID/SECRET/REDIRECT_URI/PROXY; scopes зашит: `activity:read_all`.

#### `getCurlOpts(array $extra = [])` — L24 (private)
Базовые cURL-опции (RETURNTRANSFER, timeout 30) + прокси-настройки (туннель, SOCKS5 по префиксу). Уникально для Strava — все запросы идут через этот хелпер.

#### `getProviderId()` — L39
Возвращает 'strava'.

#### `getOAuthUrl(string $state)` — L43
URL strava.com/oauth/authorize, approval_prompt=auto. null без clientId/redirectUri.

#### `exchangeCodeForTokens(string $code, string $state)` — L58
POST /api/v3/oauth/token; redirect_uri опционален. При ошибке собирает детали из errors[] и пишет дамп в `$_SESSION['strava_token_error']` (для диагностики), бросает Exception. Сохраняет athlete.id как external_athlete_id.

#### `refreshToken(int $userId)` — L111
POST grant_type=refresh_token; при ошибке Logger::warning (ленивый require). Strava возвращает expires_at как unix-время (не expires_in).

#### `fetchWorkouts(int $userId, string $startDate, string $endDate)` — L151
Refresh заранее при остатке < 5 мин (токен Strava ~6ч). Постраничный GET /athlete/activities (after/before, per_page=200, цикл до пустой страницы) — единственный провайдер с пагинацией. Затем `enrichWithDetails()` (детали+стримы+круги) и маппинг.

#### `fetchSingleActivity(int $activityId, int $userId, ?callable $onError = null): ?array` — L209 (public)
Одна активность для webhook: refresh при необходимости, `fetchActivityById()`, ретраи — повторный refresh при 401, usleep(1s)+повтор при 5xx. Передаёт последний HTTP-код через `$GLOBALS['_strava_last_http']` (хак). Используется strava_webhook.php и process_strava_webhook_retries.php.

#### `fetchActivityById(int $activityId, string $accessToken, int $userId, ?callable $onError)` — L243 (private)
GET /activities/{id}; пишет http-код/ответ в $GLOBALS; обогащает стримами и кругами, маппит в канонический формат. $onError-колбэк при любой ошибке.

#### `enrichWithDetails(array $activities, string $accessToken)` — L270 (private)
Для первых 30 активностей: GET деталей (/activities/{id}) — подтягивает HR/elevation/speed/laps, затем `enrichActivityWithStreamsAndLaps()`; usleep(100ms) между запросами (rate limit). Хвост списка остаётся без обогащения.

#### `fetchActivityStreams(?int $activityId, string $accessToken, ?string $startDateLocal)` — L310 (private)
GET /activities/{id}/streams (time,heartrate,altitude,velocity_smooth,distance,cadence,latlng); собирает timeline-точки с даунсэмплингом до ~500; темп из velocity_smooth (м/с).

#### `enrichActivityWithStreamsAndLaps(array $activity, int $activityId, string $accessToken)` — L384 (private)
Кладёт стримы в `strava_streams`; круги — из детального ответа либо отдельным запросом `fetchActivityLaps()`, в `strava_laps`.

#### `fetchActivityLaps(int $activityId, string $accessToken)` — L396 (private)
GET /activities/{id}/laps + `normalizeActivityLaps()`.

#### `normalizeActivityLaps($laps)` — L415 (private)
Нормализация кругов Strava: lap_index/name/start_time/elapsed/moving/distance/скорости/темп (из moving_time или average_speed)/HR/elevation/cadence/start-end index; сортировка по lap_index. Самая богатая lap-схема среди провайдеров (у FitParser поля другие: name/duration_seconds/...).

#### `mapStravaActivitiesToWorkouts(array $activities)` — L468 (private)
Канонический формат из start_date_local, elapsed_time, distance, sport_type, average_speed (темп через `paceFromSpeed`), HR, elevation. external_id = `strava_<id>`; timeline из strava_streams, laps из strava_laps.

#### `paceFromSpeed(?float $speedMps, string $activityType)` — L507 (private)
Темп из скорости м/с; null для не-пеших типов.

#### `mapSportType(string $sportType)` — L520 (private)
Подстрочный маппинг (run|virtualrun|treadmillrun / ride|cycle|bike / swim / walk / hike), default 'running'.

#### `isConnected(int $userId)` — L543
Стандартно.

#### `ensureIntegrationHealthy(int $userId): array` — L554 (public)
Health-check интеграции: проактивный refresh, если токену осталось < 4ч; если пуст external_athlete_id — GET /athlete и UPDATE в `integration_tokens`. Возвращает `{athlete_id_fixed, token_refreshed, error}`. Используется oauth_callback, strava_webhook, strava_daily_health_check, process_strava_webhook_retries.

#### `disconnect(int $userId)` — L611
DELETE из `integration_tokens` (без revoke на стороне Strava).

#### `ensureWebhookSubscription(): array` — L633 (public)
Идемпотентная webhook-подписка: список push_subscriptions → если есть с нужным callback_url, ок; иначе удалить все и создать новую (verify_token из .env). Требует STRAVA_WEBHOOK_CALLBACK_URL. Используется oauth_callback (нет — только Polar там; используется скриптами strava_register_webhook, strava_daily_health_check).

#### `getTokenRow(int $userId)` — L703 (private)
SELECT access/refresh/expires (без external_athlete_id — он читается отдельным запросом в ensureIntegrationHealthy).

#### `saveTokens(...)` — L713 (private)
Перед UPSERT удаляет строки других пользователей с тем же athlete_id (анти-дубль привязки одного Strava-аккаунта к двум юзерам) — уникально для Strava.

#### `listWebhookSubscriptions()` — L731 (private)
GET /push_subscriptions с client creds в query; JSON-декод с обработкой ошибок.

#### `deleteWebhookSubscription(int $subscriptionId)` — L761 (private)
DELETE /push_subscriptions/{id}; 204 — успех.

#### `createWebhookSubscription(string $callbackUrl, string $verifyToken)` — L783 (private)
POST /push_subscriptions; валидирует наличие id в ответе.

#### `callWebhookApi(string $url, array $extraOpts = [], array $successHttpCodes = [200, 204])` — L820 (private)
Общий cURL-хелпер webhook-API: возвращает `{ok, error, http_code, response}`, ошибку достаёт из message/errors[0].message.

---

## `planrun-backend/providers/SuuntoProvider.php` (697 строк)
Suunto Cloud API: OAuth (Basic auth на токен), REST cloudapi.suunto.com с нестандартными заголовками (`Authorization: <JWT>` БЕЗ "Bearer" + `Ocp-Apim-Subscription-Key`). Импорт списка /v2/workouts; для первых N тренировок качает FIT-экспорт и парсит его FitParser'ом (полный трек). Дополнительно: выгрузка тренировок PlanRun В Suunto (Workout Upload API). Используется: oauth_callback, api/suunto_webhook.php, scripts/suunto_auto_sync.php, suunto_upload_worker.php.

### class SuuntoProvider — L23
Реализация WorkoutImportProvider + FIT-обогащение + upload.

#### `__construct($db)` — L37
Читает SUUNTO_CLIENT_ID/SECRET/REDIRECT_URI/SUBSCRIPTION_KEY/SCOPES; auth/token/apiBase с зашитыми дефолтами cloudapi*.suunto.com; activityDefault (по умолчанию 'other' = ОФП); загружает карту видов спорта.

#### `env(string $key, string $default)` — L51 (private)
Обёртка над глобальным env() с защитой от null (уникальный хелпер этого провайдера).

#### `getProviderId()` — L56
Возвращает 'suunto'.

#### `loadActivityMap()` — L67 (private)
Зашитая таблица Suunto Sport id → наш тип (22 endurance-вида: бег/вело/плавание/ходьба/хайк), точечно переопределяется SUUNTO_ACTIVITY_MAP_JSON; всё прочее уйдёт в default.

#### `getOAuthUrl(string $state)` — L105
Стандартный authorize-URL; без PKCE. null без clientId/redirectUri.

#### `exchangeCodeForTokens(string $code, string $state)` — L122
Через `tokenRequest()` (Basic auth). Извлекает имя аккаунта Suunto (claim "user" JWT) как external_athlete_id. Exception при отсутствии creds или ошибке токена.

#### `refreshToken(int $userId)` — L154
`tokenRequest()` grant_type=refresh_token; пересохраняет токены (JWT живёт ~24ч).

#### `tokenRequest(array $body): ?array` — L179 (private)
POST на tokenUrl с `Authorization: Basic base64(client_id:client_secret)`; при не-200 Logger::warning и возврат тела (для извлечения текста ошибки).

#### `extractUsername(string $accessToken, array $tokenResponse)` — L208 (private)
Имя пользователя из полей ответа (user/username/sub) либо из claims JWT.

#### `decodeJwtPayload(string $jwt): ?array` — L228 (private)
Декодирование payload JWT (третий дубль этой логики после Coros/Garmin, но вынесен в отдельный метод).

#### `fetchWorkouts(int $userId, string $startDate, string $endDate)` — L243
[] + warning без SUBSCRIPTION_KEY. Авто-refresh за 60с. GET /v2/workouts?since/until (epoch-мс, limit 200). Для первых SUUNTO_SYNC_FIT_LIMIT (деф. 25) тренировок — `fetchWorkoutFit()` (полный FIT-трек), остальные через сводочный `mapSuuntoWorkout()`.

#### `fetchWorkoutByKey(int $userId, string $workoutKey): ?array` — L297 (public)
Фолбэк для webhook: эндпоинта одиночной тренировки в Suunto нет, поэтому GET /v2/workouts?limit=30 и матч по workoutKey в payload. Используется api/suunto_webhook.php.

#### `fetchWorkoutFit(int $userId, string $workoutKey, array $summary = []): ?array` — L332 (public)
Качает GET /v2/workout/exportFit/{key} (timeout 60s), валидирует сигнатуру ".FIT" (байты 8–11), пишет во временный файл и парсит `FitParser::parse()`; затем накладывает Suunto-специфику: external_id = `suunto_<key>`, тип из activityId, темп с часов из сводки. Удаляет temp-файл в finally. Используется webhook и fetchWorkouts.

#### `apiGet(string $url, string $accessToken): ?array` — L400 (private)
GET с Suunto-заголовками (JWT без Bearer + subscription key); Logger::warning и null при ошибке.

#### `extractWorkoutList($json)` — L427 (private)
Обёртки payload/data/workouts/items/results либо «голый» массив — аналог Coros::extractActivityList.

#### `mapSuuntoWorkout(array $w): ?array` — L450 (public, у других аналог private!)
Сводка Suunto → канонический формат: startTime (epoch-мс), totalTime/duration, totalDistance (м→км), тип через `mapActivityId()`, темп из avgPace/avgSpeed либо расчётный, HR из hrdata.workoutAvgHR/MaxHR либо avgHr/maxHr, ascent. external_id = `suunto_<key|startTime>`, timeline null. Public, т.к. вызывается напрямую из suunto_webhook.php.

#### `mapActivityId($activityId)` — L535 (private)
Lookup в activityMap; null/неизвестный id → activityDefault ('other'). Отличие от других провайдеров: незнакомый спорт НЕ превращается в 'running'.

#### `paceFromSpeedFields(array $w)` — L547 (private)
Темп из готовых полей сводки: avgPace (мин/км) или avgSpeed (м/с).

#### `paceFromMinPerKm(float $minPerKm)` — L557 (private)
Форматирование мин/км → `м:сс` с защитой от не-finite.

#### `uploadWorkout(int $userId, int $workoutId): array` — L575 (public)
Обратная заливка в Suunto: SuuntoFitBuilder собирает FIT-файл → POST /v2/upload/ (init) → PUT бинарника в выданный blob-URL → поллинг /v2/upload/{id} (до 20×2с). Возвращает `{status: PROCESSED|SKIPPED|ERROR, workoutKey, message}`; дубль ("exist") = SKIPPED. Удаляет FIT в finally. Единственный провайдер с экспортом данных В сервис. Используется scripts/suunto_upload_worker.php.

#### `apiJson(string $method, string $url, string $token, ?string $body): ?array` — L639 (private)
Универсальный JSON-запрос с Suunto-заголовками (для upload-флоу).

#### `isConnected(int $userId)` — L659
Стандартно.

#### `disconnect(int $userId)` — L663
DELETE из `integration_tokens`.

#### `getTokenRow(int $userId)` — L671 (private)
Идентичен Coros/Garmin-версии.

#### `saveTokens(...)` — L681 (private)
Идентичен Coros/Garmin-версии (UPSERT с COALESCE по external_athlete_id).

---

## `planrun-backend/utils/FitParser.php` (441 строка)
Парсер FIT-файлов (Garmin/Suunto/Coros/Wahoo) на базе vendor-библиотеки adriangibbons/php-fit-file-analysis. Возвращает тот же нормализованный формат, что GpxTcxParser::parse(), плюс calories/cadence/laps. Все методы статические. Вызывается из GpxTcxParser (расширение .fit) и SuuntoProvider::fetchWorkoutFit.

### class FitParser — L12
Статический фасад над phpFITFileAnalysis.

#### `parse(string $filePath, ?string $date = null): ?array` — L19 (public static)
Главная точка входа: парсит FIT, берёт record-стримы (timestamp/HR/GPS/altitude/cadence/speed/distance) и session-сводку (avg/max HR, дистанция, длительность, скорость, sport, ascent, calories, cadence). Фолбэки: дистанция из последней record-точки, длительность из диапазона timestamps, ascent из перепадов altitude, HR из валидных record-значений (30–250). Каденс ×2 (FIT хранит шаги на ногу). Строит timeline и laps; при ≤1 круге нарезает сплиты по 1 км из трека. null при ошибке парсинга/пустом record. Параметр $date фактически не используется в логике.

#### `buildTimeline(array $timestamps, $heartRates, ..., $distances): ?array` — L190 (private static)
Таймлайн с даунсэмплингом до ~500 точек; record-массивы индексируются по timestamp. Фильтры: нулевые координаты → null, HR вне 30–250 → null, каденс ×2. Темп из speed: библиотека отдаёт КМ/Ч → 3600/speed (с фиксом старого бага 1000/speed); валидный диапазон 2:00–25:00 мин/км.

#### `buildLaps(array $lapData): ?array` — L270 (private static)
Круги из lap-сообщений FIT; нормализация скаляр→массив через `toArray()` (библиотека при одном круге отдаёт скаляры). Поля: name «Круг N», distance_km, duration_seconds, avg_pace (из avg_speed, тут м/с → 1000/v), avg/max HR, cadence ×2, elevation_gain.

#### `buildSplitsFromTimeline(?array $timeline, float $intervalKm = 1.0): ?array` — L331 (private static)
Нарезка сплитов фиксированной длины из таймлайна (аналог «Splits» Strava), когда автолапы не писались: аккумулирует HR/каденс/набор по сегменту, flush на границах километра (замыкание), хвост > 50 м — отдельный сплит.

#### `paceStr(float $secPerKm): ?string` — L393 (private static)
Темп из сек/км; null вне диапазона 2:00–30:00.

#### `getVal($data, $key)` — L404 (private static)
Значение из массива по ключу либо скаляр как есть.

#### `getIdx($data, int $index)` — L415 (private static)
Значение по числовому индексу с array_values.

#### `toArray($v): array` — L426 (private static)
Нормализация значения FIT-поля к индексированному массиву (скаляр → [скаляр]).

#### `firstVal($data)` — L434 (private static)
Первый элемент массива либо скаляр (для session-полей).

---

## `planrun-backend/utils/GpxTcxParser.php` (434 строки)
Парсер GPX и TCX (а для .fit делегирует FitParser). Все методы статические. Вызывается из WorkoutController::uploadWorkoutFile (L330). Особенность — продвинутый расчёт дистанции: extension-дистанция > speed×время > Haversine с коррекцией на кривизну и частоту записи.

### class GpxTcxParser — L5
Статический парсер файлов тренировок.

#### `parse(string $filePath, string $date = null): ?array` — L12 (public static)
Диспетчер по расширению файла: gpx → parseGpx, tcx → parseTcx, fit → FitParser::parse (lazy require). null для прочих.

#### `parseGpx(string $filePath, ?string $date): ?array` — L27 (private static)
simplexml + XPath (с namespace и без); из trkpt берёт lat/lon/time/ele и extensions (hr/cad/distance/speed — прямые, вложенные типа gpxtpx:TrackPointExtension и без namespace). Фильтр HR 30–250, каденс ×2 (Garmin пишет на ногу). Дистанция: extension-кумулятив (если ratio к Haversine 0.5–2.0) > speed×dt > Haversine×коэффициент 1.007–1.025 по частоте записи. Темп из точных секунд; avg/max HR из точек. Возвращает канонический формат + timeline; activity_type всегда 'running'.

#### `parseTcx(string $filePath, ?string $date): ?array` — L194 (private static)
Trackpoint'ы по XPath/обходу Activities→Lap→Track; дистанция из последнего DistanceMeters либо Haversine; тип из атрибута Sport (bik→cycling, walk→walking). Беднее GPX-ветки: без HR/каденса/speed в точках, темп через минутный `paceFromKmAndMinutes`, без avg/max HR в выходе.

#### `buildTimeline(array $points): ?array` — L253 (private static)
Даунсэмплинг до ~500 точек; кумулятивная дистанция по Haversine; темп: приоритет device speed (м/с), фолбэк из GPS-сегмента (dt<300с, seg>5м); валидный диапазон 2:00–15:00.

#### `calculateDistance(array $points): float` — L316 (private static)
Haversine-сумма с фильтром GPS-выбросов (>1 км за <5с) и коррекцией на кривизну: по изменению bearing между сегментами дуга ≈ хорда×(1+θ²/24).

#### `bearing(float $lat1, $lon1, $lat2, $lon2): float` — L365 (private static)
Начальный азимут между точками в радианах [0, 2π).

#### `haversineKm(float $lat1, $lon1, $lat2, $lon2): float` — L375 (private static)
Классический Haversine, R=6371 км.

#### `calculateSpeedDistance(array $points): float` — L390 (private static)
Дистанция как sum(speed×dt) из device speed (точнее GPS при джиттере); dt ограничен 30с; 0 если speed-данных нет.

#### `calculateElevationGain(array $points): ?int` — L414 (private static)
Сумма положительных перепадов ele; null если 0.

#### `paceFromKmAndMinutes(float $km, int $minutes): string` — L426 (private static)
Темп из минут. БАГ: при округлении секунд до 60 ветка делает `$s += 60` вместо `-= 60` → темп вида «5:120» (у провайдеров та же функция исправна: `$s = 0; $m++`). Также `return null` при декларации string.

---

## `planrun-backend/validators/BaseValidator.php` (115 строк)
Базовый класс валидаторов: накопитель ошибок по полям + примитивные проверки. Наследуют все 4 валидатора ниже.

### class BaseValidator — L6
Хранит `$errors` (field => [messages]).

#### `addError($field, $message)` — L13 (protected)
Добавляет сообщение в массив ошибок поля.

#### `hasErrors()` — L23 (public)
true если есть хоть одна ошибка.

#### `getErrors()` — L30 (public)
Весь массив ошибок.

#### `getFirstError()` — L37 (public)
Первое сообщение первого поля либо null.

#### `validateRequired($value, $fieldName)` — L47 (protected)
empty()-проверка с исключением для '0'/0; пишет ошибку и возвращает false.

#### `validateType($value, $type, $fieldName)` — L58 (protected)
Проверка типов int/float/string/array/date (date — через validateDate).

#### `validateDate($value, $format = 'Y-m-d')` — L88 (protected)
Строгая проверка через DateTime::createFromFormat + обратное форматирование. НЕ добавляет ошибку сама (в отличие от остальных validate*) — вызывающие в Week/WorkoutValidator используют её напрямую, так что невалидная дата ошибкой не становится.

#### `validateRange($value, $min, $max, $fieldName)` — L96 (protected)
Числовой диапазон включительно.

#### `validateLength($value, $min, $max, $fieldName)` — L107 (protected)
Длина строки в символах (mb_strlen).

---

## `planrun-backend/validators/ExerciseValidator.php` (94 строки)
Валидация CRUD-операций над упражнениями дня плана. Используется ExerciseService (все 4 метода).

### class ExerciseValidator extends BaseValidator — L8

#### `validateAddExercise($data)` — L13
Обязательные plan_day_id/category/name; plan_day_id int; category из ['run','strength','cardio','flexibility','other']; name 1–255 символов.

#### `validateUpdateExercise($data)` — L41
Обязателен exercise_id (int); category, если передана, из того же списка.

#### `validateDeleteExercise($data)` — L63
Обязателен exercise_id (int).

#### `validateReorderExercises($data)` — L78
Обязательны plan_day_id (int) и exercise_ids (array).

---

## `planrun-backend/validators/TrainingPlanValidator.php` (36 строк)
Минимальная валидация операций над планом. Используется TrainingPlanService.

### class TrainingPlanValidator extends BaseValidator — L8

#### `validateRegeneratePlan($data)` — L13
Только опциональная проверка user_id как int (required-проверки нет).

#### `validateCheckStatus($data)` — L27
Идентична validateRegeneratePlan (дубль).

---

## `planrun-backend/validators/WeekValidator.php` (120 строк)
Валидация недель и дней плана. Используется WeekService (все 5 методов). Список типов дня (`$validTypes`) продублирован в 3 методах.

### class WeekValidator extends BaseValidator — L8

#### `validateAddWeek($data)` — L13
Обязательны week_number (int, 1–1000) и start_date; дата проверяется validateDate (результат игнорируется — ошибка не добавляется).

#### `validateDeleteWeek($data)` — L34
Обязателен week_id (int).

#### `validateAddTrainingDay($data)` — L49
Обязательны week_id (int), day_of_week (int, 1–7), type из 12 типов ('rest','easy','long','tempo','interval','fartlek','marathon','control','race','other','free','sbu'); date опциональна.

#### `validateAddTrainingDayByDate($data)` — L82
Вариант без week_id/day_of_week: обязательны date и type (тот же список типов).

#### `validateUpdateTrainingDay($data)` — L105
Обязательны day_id (int) и type (тот же список типов).

---

## `planrun-backend/validators/WorkoutValidator.php` (48 строк)
Валидация запросов дня и сохранения результата тренировки. Используется WorkoutService (L804, L1175).

### class WorkoutValidator extends BaseValidator — L8

#### `validateGetDay($data)` — L13
Обязательна date (формат Y-m-d через validateDate, результат не добавляет ошибку).

#### `validateSaveResult($data)` — L27
Обязательны date/week/day; activity_type_id (если есть) int; week int 1–1000. Для day тип не проверяется.

---

## Сравнение провайдеров с интерфейсом WorkoutImportProvider

| Аспект | Coros | Garmin | Huawei | Polar | Strava | Suunto |
|---|---|---|---|---|---|---|
| OAuth client auth | body/basic (конфиг) | body + PKCE | body | Basic | body | Basic |
| PKCE | опционально | обязателен | нет | нет | нет | нет |
| refreshToken | реальный | реальный | реальный | заглушка (токен вечный) | реальный | реальный |
| fetchWorkouts при ошибке | [] | [] | Exception (!) | [] | [] | [] |
| external_athlete_id | из JWT/ответа | из JWT | нет (!) | x_user_id | athlete.id | claim user JWT |
| timeline | null | null | нет ключа (!) | samples+route | streams API | FIT-экспорт |
| laps | нет | нет | нет | нет | да | да (из FIT) |
| Доп. API | — | — | — | webhook partner API, registerUser, DELETE user при disconnect | webhook, health-check, прокси, анти-дубль athlete_id | FIT-экспорт, upload В Suunto |
| Активен в проде | push-эндпоинт есть, creds нет | creds нет | только redirect_uri в .env | да | да | да |

Отклонения от канона:
- **HuaweiHealthProvider** — выход fetchWorkouts без avg_heart_rate/max_heart_rate/elevation_gain/timeline; external_id без префикса 'huawei_'; единственный бросает исключения из fetchWorkouts; getOAuthUrl требует clientSecret.
- **PolarProvider::refreshToken** — не обновляет токен (семантика интерфейса нарушена осознанно).
- **SuuntoProvider::mapSuuntoWorkout** — public (нужен webhook'у), у остальных мапперы private.
- **GarminProvider/PolarProvider/GpxTcxParser `paceFromKmAndMinutes`** — декларация `: string` при `return null` (PHP TypeError при km<=0; фактически защищено вызывающими).
- **GpxTcxParser::paceFromKmAndMinutes L431** — баг переноса секунд: `$s += 60` вместо `-= 60`.
