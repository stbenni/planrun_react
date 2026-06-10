# Backend services 1/6 (Admin…ChatService) — справочник функций

## `planrun-backend/services/AdminService.php` (116 строк)
Админские операции над пользователями: список с пагинацией/поиском, чтение и обновление профиля. Используется AdminController.

### class AdminService — L9
Наследует BaseService. Работает с таблицей `users` напрямую через mysqli prepared statements.

#### `listUsers(int $page, int $perPage, string $search)` — L14
Возвращает страницу пользователей (id, username, email, role, created_at, training_mode, goal_type) с total/total_pages. Поиск LIKE по username/email. Читает `users` (2 запроса: COUNT + выборка).

#### `getUser(int $userId)` — L58
Возвращает данные одного пользователя через `getUserData()` (user_functions.php), без поля password; JSON-поля `preferred_days`/`preferred_ofp_days` декодирует в массивы. Бросает NotFoundException, если нет.

#### `updateUser(int $userId, int $currentAdminId, array $data)` — L76
Обновляет role (валидация по `UserRoles::getAll()` из config/constants.php) и/или email (FILTER_VALIDATE_EMAIL, пустой → NULL). Запрещает менять роль самому себе. Пишет в `users`, после чего вызывает `clearUserCache($userId)`.

## `planrun-backend/services/AiObservabilityService.php` (76 строк)
Generic-логгер AI runtime-событий (чат, tools, recalc-триггеры) в таблицу `ai_runtime_events`. Не путать с AiPlanGenerationEventLogger (узкий plan-generation).

### class AiObservabilityService — L5
Наследует BaseService. Статический флаг `$schemaEnsured` кэширует создание схемы на процесс.

#### `ensureSchema()` — L8
Лениво создаёт таблицу `ai_runtime_events` (CREATE TABLE IF NOT EXISTS) с индексами по trace_id, surface+created_at, user_id+created_at. При ошибке логирует и не падает.

#### `createTraceId(string $surface)` — L36
Генерирует trace ID вида `<surface-slug>-<12 hex>` из random_bytes. Без побочных эффектов.

#### `logEvent(string $surface, string $eventType, string $status, array $payload, ?int $userId, ?string $traceId, ?int $durationMs)` — L41
Пишет событие в `ai_runtime_events` (payload как JSON). No-op при `AI_OBSERVABILITY_ENABLED != 1`. Сам вызывает ensureSchema() и createTraceId() при отсутствии traceId. Вызывается из ChatService, LlmGateway и др.

## `planrun-backend/services/AiPlanGenerationEventLogger.php` (520 строк)
Observability-слой генерации планов (PR6 / Phase D.1): пишет события в `ai_plan_generation_events` для метрик bad-plan rate, repair rate, токенов, cohort-разбивки и выбора модели.

### class AiPlanGenerationEventLogger — L20
Наследует BaseService. Включается env `PLAN_AI_EVENT_LOG_ENABLED` (default 1).

#### `recordSuccess(int $userId, string $jobType, array $generationMetadata, array $trainingState, int $durationMs, ?string $traceId, array $extra)` — L33
Записывает успешное событие генерации (status='success') через buildRow()+insert(). Возвращает insert id или null (выключено/ошибка). Пишет в `ai_plan_generation_events`.

#### `recordFailure(int $userId, string $jobType, $errorOrMessage, array $generationMetadata, array $trainingState, int $durationMs, ?string $traceId, array $extra)` — L65
То же для неудачи (status='failure'); из Throwable/строки извлекает error_code и error_message (до 1000 символов).

#### `getRecentEvents(int $limit, array $filters)` — L96
Последние события (limit 1..500) с фильтрами user_id/cohort/status/since; JSON-колонки issue/repair/warning codes декодирует в массивы. Читает `ai_plan_generation_events`. Используется AdminController (admin-дашборд).

#### `getMetricsSummary(int $hours)` — L176
Агрегаты за окно (1ч..30дн): total/success/failure/blocked/repaired, bad_plan_rate, repair_rate, разбивка by_cohort и by_model (avg duration/tokens/complexity). 3 SQL-агрегата по `ai_plan_generation_events`. Используется AdminController.

#### `deriveCohort(array $trainingState)` — L256
Чистая функция: выводит когорту из special_population_flags / planning_scenario.flags / goal_realism.severity. Приоритет: pregnant_or_postpartum > return_after_injury > pain_signal > illness_signal > unrealistic_goal > healthy.

#### `isEnabled()` — L284 (private)
Проверка env `PLAN_AI_EVENT_LOG_ENABLED == 1`.

#### `isDeepSeekOffPeakNow()` — L293 (private static)
true, если текущее UTC-время в окне скидок DeepSeek 16:30–00:30 (off-peak: Reasoner −75%, V3 −50%). Чистая функция от gmdate().

#### `buildRow(int $userId, string $jobType, array $metadata, array $trainingState, int $durationMs, ?string $traceId, array $extra)` — L301 (private)
Собирает ассоциативный массив строки события: cohort (deriveCohort), pricing_tier (off-peak), поля quality_gate (issue/repair/warning codes), модель, токены, retries, prompt_version, trace_id, полный metadata.

#### `insert(array $row)` — L363 (private)
INSERT в `ai_plan_generation_events`: единый словарь колонка=>[тип,значение] (#96), из которого выводятся placeholders/bind-строка; bind_param через массив референсов. Возвращает insert_id или null с logError.

#### `fetchOne(string $sql, string $types, array $params)` — L435 (private)
Универсальный helper: prepared SELECT, возвращает первую строку или [].

#### `fetchAll(string $sql, string $types, array $params)` — L452 (private)
То же, возвращает все строки массивом.

#### `enrichAggregateRow(array $row)` — L473 (private)
Постобработка агрегатной строки: приведение счётчиков к int, округление avg-полей, расчёт bad_plan_rate/repair_rate/blocked_rate/failure_rate. Вызывается через array_map из getMetricsSummary.

#### `decodeJsonArray($value)` — L510 (private)
Безопасный json_decode JSON-колонки в массив (иначе []).

## `planrun-backend/services/AthleteSignalsService.php` (424 строки)
Сводка «сигналов атлета» за окно дат: пост-тренировочный feedback (через PostWorkoutFollowupService) + эвристический анализ заметок дня/недели (боль, усталость, сон, болезнь, стресс, поездки) → риск-скор и planning biases для генератора планов.

### class AthleteSignalsService — L6
Наследует BaseService; держит экземпляр PostWorkoutFollowupService.

#### `__construct(mysqli $db)` — L9
Создаёт PostWorkoutFollowupService($db).

#### `getRecentSignalsSummary(int $userId, int $days, ?string $endDate)` — L14
Окно «последние N дней» (default 14) до endDate (или сегодня UTC) → делегирует getSignalsBetween(). Вызывается из TrainingStateBuilder.

#### `getSignalsBetween(int $userId, string $startDate, string $endDate)` — L23
Главный метод: валидирует даты, собирает feedback-аналитику (`PostWorkoutFollowupService::getFeedbackAnalyticsBetween`), заметки (getAthleteNotesBetween), метрики заметок и мержит в итоговую сводку. При невалидном входе — buildEmptySignalsSummary().

#### `getAthleteNotesBetween(int $userId, string $startDate, string $endDate)` — L35 (private)
Читает собственные заметки атлета: `plan_day_notes` (до 20, исключая авто-заметки «Самочувствие после тренировки:»; author_id = user_id) и `plan_week_notes` (до 12, окно недель сдвинуто на −6 дней). Сортирует по дате/created_at убыв.

#### `buildNoteMetrics(array $notes)` — L89 (private)
Агрегирует анализ заметок: счётчики по сигналам (pain/fatigue/sleep/illness/stress/travel/positive/recovery), булевы флаги, planning_biases (protect_injury, prefer_recovery, sleep_guard...), excerpts (до 4), highlights (до 6), note_risk_score (cap 1.0) и note_risk_level.

#### `analyzeNote(string $content)` — L185 (private)
Regex-эвристики по русским ключевым словам для 8 сигналов; считает risk_weight (illness 0.70, pain 0.55, fatigue 0.30, sleep 0.22, stress 0.20, travel 0.18, positive −0.08) и highlights. Чистая функция.

#### `mergeSignalSummaries(array $feedback, array $noteMetrics, string $startDate, string $endDate)` — L251 (private)
Сливает feedback-метрики и note-метрики: overall_risk_score = max из источников, overall_risk_level, объединённые highlights/biases, prompt_summary.

#### `buildFeedbackHighlights(array $feedback)` — L306 (private)
Highlights из feedback: недавняя боль, усталость, всплеск subjective_load_delta >= 0.75.

#### `buildFeedbackBiases(array $feedback)` — L320 (private)
Biases из feedback: protect_injury (боль), prefer_recovery (усталость или load_delta >= 0.45).

#### `buildPromptSummary(array $feedback, array $noteMetrics, string $overallRiskLevel)` — L331 (private)
Однострочная текстовая сводка для LLM-промпта («post-workout feedback: N ответов...; notes: pain=…; overall=…»).

#### `buildEmptySignalsSummary(string $startDate, string $endDate)` — L370 (private)
Нулевая сводка (все счётчики 0, risk low) для невалидного входа.

#### `trimExcerpt(string $content, int $limit)` — L403 (private)
Схлопывает пробелы и обрезает до 140 символов с «…».

#### `resolveRiskLevelFromScore(float $riskScore, bool $hasPrimarySignal, bool $hasSecondarySignal)` — L411 (private)
high при любом из флагов или score >= 0.75; moderate при >= 0.35; иначе low.

#### `isValidDate(string $date)` — L421 (private)
Проверка формата Y-m-d regex'ом.

## `planrun-backend/services/AuthService.php` (380 строк)
Аутентификация: логин по паролю (+JWT), выдача/обновление/отзыв токенов, сброс пароля по email, валидация Bearer-токена.

### class AuthService — L10
Наследует BaseService; держит JwtService.

#### `__construct($db)` — L14
Создаёт JwtService($db).

#### `login($username, $password, $useJwt, $deviceId)` — L29
Вызывает глобальный `login()` из auth.php (сессия + проверка пароля); при $useJwt дополнительно выдаёт access/refresh токены через JwtService (пишет в таблицу refresh-токенов). 401 при неверных кредах. Читает $_SESSION.

#### `issueTokens(int $userId, string $username, ?string $deviceId)` — L70
Выдаёт JWT-пару без проверки пароля — для внешне проверенной личности (Telegram Mini App). Вызывается из AuthController.

#### `logout($refreshToken)` — L84
Отзывает refresh token (JwtService::revokeRefreshToken) и завершает сессию через глобальный `logout()`.

#### `refreshToken($refreshToken, $deviceId)` — L104
Обновляет access token через JwtService::refreshAccessToken; 401 при невалидном refresh.

#### `requestPasswordReset($emailOrUsername)` — L128
Ищет пользователя по email или username (`users`); удаляет старые и создаёт новый токен в `password_reset_tokens` (TTL 1 час); строит reset-URL из APP_URL/HTTP_HOST; шлёт письмо через EmailService (SMTP, если MAIL_HOST+MAIL_USERNAME заданы) либо через sendPasswordResetViaMail(). Возвращает {sent, message?}; не-исключение при «не найден» (но раскрывает существование аккаунта в message).

#### `sendPasswordResetViaMail($toEmail, $username, $resetUrl, $expiresMin)` — L229 (private)
Fallback-отправка письма сброса через PHP `mail()` с HTML-телом (без PHPMailer). Читает env MAIL_FROM_*.

#### `confirmPasswordReset($token, $newPassword)` — L266
Валидирует токен из `password_reset_tokens` (не истёк), пароль >= 6 символов; пишет новый password_hash в `users`, удаляет токен.

#### `assertPasswordResetTableAvailable()` — L313 (private)
SHOW TABLES LIKE 'password_reset_tokens'; 503, если миграция не применена.

#### `validateJwtToken()` — L326
Извлекает Bearer из HTTP_AUTHORIZATION/REDIRECT_HTTP_AUTHORIZATION, верифицирует через JwtService::verifyToken (type=access), плюс проверяет существование пользователя в БД (удалённый юзер → null → 401, а не 500 по FK). Возвращает {user_id, username} или null.

#### `userExists(int $userId)` — L366 (private)
SELECT 1 FROM `users`; при сбое prepare — fail-open (true), чтобы временная ошибка БД не разлогинивала всех.

## `planrun-backend/services/AvatarService.php` (545 строк)
Обработка и раздача аватаров: нормализация загруженного изображения (EXIF, квадрат-кроп, webp/jpg), миниатюры sm/md/lg, атомарная запись, HTTP-раздача с ETag. Все методы статические, без БД.

### class AvatarService — L12
Не наследует BaseService. Константы (все private): `LOCAL_PATH_PREFIX` L13, `LOCAL_FILE_PATTERN` L14, `ALLOWED_MIMES` L15, `VARIANT_SIZES` L21 (sm=96, md=256, lg=384), `MAIN_SIZE` L26 (512), `MAX_UPLOAD_BYTES` L27 (5 MiB), `MAX_PIXELS` L28 (40 Мпикс), `JPEG_QUALITY` L29, `WEBP_QUALITY` L30.

#### `storeUploadedAvatar(array $file, int $userId)` — L39 (static)
Принимает элемент $_FILES: проверяет is_uploaded_file и размер, декодирует (loadImageFromPath), кропит в квадрат, пишет основной файл 512px + 3 варианта в `/uploads/avatars/` (webp при наличии GD-webp, иначе jpg). Возвращает {avatar_path, variants}. GD-ресурсы освобождает в finally.

#### `deleteAvatarByPath($avatarPath)` — L88 (static)
Удаляет основной файл и все возможные варианты (по всем расширениям) из uploads/avatars; не трогает внешние URL.

#### `normalizeStoredAvatarPath($avatarPath)` — L107 (static)
Нормализует значение перед записью в БД: null/'' → null; http(s)-URL — как есть; локальное имя по паттерну → канонический `/uploads/avatars/<file>`; иначе invalid.

#### `serveRequestedAvatar($requestedFile, $requestedVariant)` — L139 (static)
Раздаёт аватар или миниатюру (вариант создаётся on-the-fly при отсутствии). При успехе вызывает sendFileResponse() (заканчивается exit). false — если файл не managed/не найден.

#### `ensureAllVariantsForFileName(string $fileName)` — L169 (static)
Догенерирует отсутствующие миниатюры всех вариантов для существующего аватара; map variant=>absolutePath. Используется scripts/backfill_avatar_variants.php.

#### `isManagedAvatarFileName(string $fileName)` — L184 (static)
Имя соответствует LOCAL_FILE_PATTERN (avatar_<id>_<ts>[_token].<ext>).

#### `ensureVariantForFileName(string $fileName, string $variant)` — L188 (private static)
Возвращает путь варианта; если файла нет — декодирует основной, кропит, пишет нужный размер. Ошибки логирует Logger::warning и возвращает null.

#### `loadImageFromPath(string $path)` — L227 (private static)
getimagesize → валидация mime/размеров/MAX_PIXELS → imagecreatefrom* по mime → truecolor+alpha → applyExifOrientation. Возвращает [GdImage, meta]. Бросает InvalidArgumentException/RuntimeException.

#### `applyExifOrientation($image, string $path, string $mime)` — L276 (private static)
Для JPEG читает EXIF Orientation (cases 2–8) и применяет imagerotate/imageflip.

#### `cropToSquare($sourceImage, int $width, int $height)` — L323 (private static)
Центрированный квадратный кроп на прозрачный канвас (imagecopyresampled).

#### `writeResizedImage($sourceImage, int $targetSize, string $absolutePath)` — L337 (private static)
Ресайз квадрата до targetSize и сохранение через saveImageAtomically; канвас освобождает в finally.

#### `saveImageAtomically($image, string $absolutePath)` — L363 (private static)
Создаёт каталог, пишет во временный файл (.tmp-<hex>) по расширению (webp/png/jpeg) и публикует rename'ом; chmod 0644.

#### `saveJpegWithBackground($image, string $path)` — L392 (private static)
JPEG не поддерживает альфу — подкладывает белый фон через imagecopy и сохраняет imagejpeg.

#### `createTransparentCanvas(int $width, int $height)` — L410 (private static)
Truecolor-канвас с сохранением альфы, залитый прозрачным.

#### `sendFileResponse(string $absolutePath)` — L424 (private static)
HTTP-ответ файла: ETag/Last-Modified, 304 при совпадении, Cache-Control max-age=86400, readfile + exit.

#### `mimeByPath(string $absolutePath)` — L449 (private static)
MIME по расширению (jpg/png/gif/webp), иначе octet-stream.

#### `buildBaseName(int $userId)` — L459 (private static)
`avatar_<userId>_<time>_<8hex>`.

#### `buildVariantFileName(string $mainFileName, string $variant)` — L463 (private static)
`<base>__<variant>.<preferredExt>`.

#### `absolutePathForVariant(string $mainFileName, string $variant)` — L468 (private static)
avatarDir() + имя варианта.

#### `absolutePathForFileName(string $fileName)` — L472 (private static)
avatarDir() + имя файла.

#### `listManagedAbsolutePaths(string $mainFileName)` — L479 (private static)
Основной путь + все варианты во всех расширениях (для удаления).

#### `extractLocalFileName($avatarPath)` — L492 (private static)
basename от строки, не-URL, проверка isManagedAvatarFileName; иначе null. Защита от path traversal.

#### `normalizeVariantName($variant)` — L506 (private static)
sm/md/lg или 'full'.

#### `preferredOutputExtension()` — L515 (private static)
'webp' при наличии imagewebp, иначе 'jpg'.

#### `ensureAvatarDir()` — L519 (private static)
mkdir uploads/avatars при отсутствии + проверка записи; RuntimeException при сбое.

#### `avatarDir()` — L530 (private static)
`<project root>/uploads/avatars/`.

#### `assertImageSupport()` — L534 (private static)
RuntimeException, если расширение GD не загружено.

#### `destroyImage($image)` — L540 (private static)
imagedestroy, если GdImage.

## `planrun-backend/services/BaseService.php` (78 строк)
Абстрактный базовый класс всех сервисов: хранит $db (mysqli), даёт обёртки логирования и фабрики типизированных исключений.

### class BaseService — L9 (abstract)

#### `__construct($db)` — L12
Сохраняет соединение mysqli в protected $db.

#### `logError($message, $context)` — L19 (protected)
Logger::error.

#### `logInfo($message, $context)` — L26 (protected)
Logger::info.

#### `logDebug($message, $context)` — L33 (protected)
Logger::debug.

#### `throwException($message, $code, $context)` — L40 (protected)
Логирует и бросает AppException (lazy require exceptions/AppException.php).

#### `throwValidationException($message, $validationErrors)` — L49 (protected)
Логирует и бросает ValidationException.

#### `throwNotFoundException($message, $context)` — L58 (protected)
Бросает NotFoundException (404), без логирования.

#### `throwUnauthorizedException($message, $context)` — L66 (protected)
Бросает UnauthorizedException (401).

#### `throwForbiddenException($message, $context)` — L74 (protected)
Бросает ForbiddenException (403).

## `planrun-backend/services/ChatActionParser.php` (212 строк)
Санитизация ответа LLM (вырезание reasoning-утечек, английских терминов, emoji) и зачистка legacy ACTION-блоков. Исполнение действий ушло в native tool calling; здесь остался safety net.

### class ChatActionParser — L13
Константа `ACTION_TOOLS` L19 (private) — список legacy-тулов для regex зачистки.

#### `__construct($db, ChatToolRegistry $toolRegistry, ChatConfirmationHandler $confirmationHandler)` — L24
Сохраняет зависимости (toolRegistry фактически больше не используется в логике).

#### `isConfirmationMessage(string $text)` — L33
Proxy для обратной совместимости → ChatConfirmationHandler::isConfirmationMessage. В продакшен-коде не вызывается (только тесты).

#### `sanitizeResponse(string $text)` — L40
Чистит ответ LLM: [THINK]/<think>-блоки, спецтокены `<|...|>`, английские leak-префиксы («We need to...»), отрезает латинский пролог перед первой кириллицей (20–150 симв.), `\w+[ARGS]{...}`, фиксит Bt/Пt → Вт/Пт; затем replaceEnglishTerms(), stripEmoji(), logLeakedEnglish().

#### `parseAndExecuteActions(string $text, int $userId, array $history, ?string $currentUserMessage, bool &$planWasUpdated, array $alreadyUsedTools)` — L98
Сейчас только вырезает остаточные `<!-- ACTION ... -->` и JSON `{"action":"add_training_day"...}` блоки (stripAllActionBlocks); ничего не исполняет, $planWasUpdated не меняет. Вызывается из ChatService (stream и non-stream).

#### `stripAllActionBlocks(string $text)` — L111 (private)
preg_replace HTML-комментариев ACTION для тулов из ACTION_TOOLS.

#### `replaceEnglishTerms(string $text)` — L118 (private)
Словарная замена ~100 английских терминов/дней/месяцев на русские (3 regex на термин); fast-path #17 — пропуск, если в тексте нет латинских слов.

#### `stripEmoji(string $text)` — L188 (private)
Серия preg_replace юникод-диапазонов emoji/вариационных селекторов/ZWJ; схлопывает двойные пробелы.

#### `logLeakedEnglish(string $text)` — L206 (private)
Находит латинские слова >= 4 букв в кириллическом окружении, фильтрует allow-list (VDOT, ACWR, Strava...) и пишет Logger::warning.

## `planrun-backend/services/ChatConfirmationHandler.php` (470 строк)
Обработка подтверждений в чате: когда AI предложил действие и юзер ответил «да» — парсит последнее AI-сообщение regex'ами и выполняет соответствующий tool через ChatToolRegistry (детерминированный fallback к native tool calling).

### class ChatConfirmationHandler — L12

#### `__construct($db, ChatToolRegistry $toolRegistry)` — L17
Сохраняет зависимости.

#### `isConfirmationMessage(string $text)` — L22
true для коротких (<= 50 симв.) согласий: точные слова («да», «ок», «давай»...), паттерны «этого достаточно», составные «да, убери» — но не отрицания («да нет», «погоди»). Чистая функция.

#### `tryHandleSwapConfirmation(string $content, array $history, int $userId, array &$messages, array &$toolsUsed)` — L41
Если короткое согласие после AI-предложения «поменять местами» (и без нумерованного списка вариантов) — извлекает 2 даты из текста и исполняет `swap_training_days` через toolRegistry; дописывает tool_call+tool в $messages, имя в $toolsUsed. Вызывается из ChatService::streamResponse.

#### `tryHandleReplaceWithRaceConfirmation(string $content, array $history, int $userId, array &$messages, array &$toolsUsed)` — L65
Подтверждение замены «сегодня → забег (полу/марафон), завтра → лёгкий»: парсит предложение (parseReplaceWithRaceProposal) и 2 даты, делает два `update_training_day`; при ошибке второго — откатывает первый по original_type/description из ответа тула.

#### `tryHandleGenericUpdateConfirmation(string $content, array $history, int $userId, array &$messages, array &$toolsUsed)` — L106
Универсальный диспетчер подтверждений: проверяет, что последнее AI-сообщение содержит глагол действия, и по очереди пробует recalculate → generate_next_plan → delete → move → copy → add → log_workout → update_profile → update.

#### `tryExtractFromLastProposal(array $history, int $userId)` — L124
Извлекает {date, type, description} из последнего AI-сообщения (дата из «для **... на <дата>**» через DateResolver, тип по русским названиям, описание после «Детали:»). НЕ ИМЕЕТ ВЫЗОВОВ в кодовой базе — кандидат на удаление.

#### `getLastAssistantMessage(array $history)` — L164 (private)
Последнее сообщение с sender_type='ai' из истории (формат chat_messages).

#### `extractSwapDatesFromText(string $text, int $userId)` — L173
Извлекает >= 2 уникальных дат из текста: Y-m-d, «сегодня/завтра/послезавтра», русские дни недели (ближайший вперёд); сортирует, возвращает первые две или null. Таймзона юзера через getUserTimezone(). Public (используется тестами через рефлексию и внутренними методами).

#### `extractCancelDateFromText(string $text, int $userId)` — L200 (private)
Ищет дату в 80 символах ПОСЛЕ глагола отмены/удаления — чтобы не зацепить «сегодня» в начале фразы.

#### `extractSingleDateFromText(string $text, int $userId)` — L208 (private)
Одна дата: Y-m-d / сегодня / завтра / послезавтра, иначе DateResolver::resolveFromText.

#### `extractReplaceDatesFromText(string $text, int $userId)` — L221 (private)
Чистый алиас extractSwapDatesFromText (дубль).

#### `getUserTz(int $userId)` — L225 (private)
DateTimeZone юзера (getUserTimezone), fallback Europe/Moscow.

#### `addToolCallToMessages(string $toolName, array $args, string $output, array &$messages, array &$toolsUsed)` — L230 (private)
Дописывает в $messages пару assistant(tool_calls)+tool и имя в $toolsUsed (общий код всех tryExecute*).

#### `tryExecuteDeleteFromProposal(string $text, int $userId, array &$messages, array &$toolsUsed)` — L239 (private)
Глагол удаления/отмены + объект (тренировка/день/...) в пределах 40 симв. → дата из окрестности глагола → tool `delete_training_day`.

#### `tryExecuteMoveFromProposal(...)` — L253 (private)
Глагол переноса + 2 даты → tool `move_training_day` (source_date, target_date).

#### `tryExecuteAddFromProposal(...)` — L264 (private)
Глагол добавления + объект/дата → parseGenericUpdateProposal → tool `add_training_day`.

#### `tryExecuteLogWorkoutFromProposal(...)` — L275 (private)
Глагол фиксации («записываю», «зафиксирую») → дата (default сегодня), дистанция в км (обязательна), опционально минуты/чч:мм:сс/пульс → tool `log_workout`.

#### `tryExecuteUpdateFromProposal(...)` — L298 (private)
parseGenericUpdateProposal → tool `update_training_day`; при ошибке not_found/no_plan_for_date — fallback на `add_training_day`.

#### `tryExecuteRecalculateFromProposal(...)` — L318 (private)
«пересчитаю/адаптирую план» → reason из «потому что/из-за...» → tool `recalculate_plan`.

#### `tryExecuteGenerateNextPlanFromProposal(...)` — L329 (private)
«создам/сгенерирую новый план» → tool `generate_next_plan` без аргументов.

#### `tryExecuteCopyFromProposal(...)` — L337 (private)
«скопирую/продублирую» + 2 даты → tool `copy_day`.

#### `tryExecuteUpdateProfileFromProposal(...)` — L348 (private)
«обновлю вес/рост/цель/...» → regex-паттерны 8 полей (weight_kg, height_cm, sessions_per_week, easy_pace_sec, race_distance, race_target_time, race_date, goal_type) с маппингом значений → tool `update_profile` (первое совпавшее поле).

#### `parseGenericUpdateProposal(string $text, int $userId)` — L389 (private)
Из текста предложения: дата (Y-m-d/сегодня/завтра/DateResolver/default сегодня), тип по русским ключевым словам (+эвристики по км/темпу), description через extractDescriptionFromProposal. null, если тип/описание не распознаны.

#### `extractDescriptionFromProposal(string $text, string $type)` — L422 (private)
Собирает description «<Тип>: X км[, темп M:SS][. ходьба][. Заминка: Y км]» из км/темпа/ходьбы/заминки в тексте.

#### `parseReplaceWithRaceProposal(string $text, int $userId)` — L438 (private)
Парсит предложение «сегодня полумарафон X км за время, завтра лёгкий Y км [темп]» → {today: {type:race|marathon, description}, tomorrow: {type:easy, description}} или null.

## `planrun-backend/services/ChatContextBuilder.php` (1278 строк)
Сборка текстового контекста пользователя для AI-чата: профиль, цель, текущий план, статистика, тренерская сводка (compliance, ACWR), последние тренировки/wellness, память и суммаризация истории. Также data-доступ для чат-тулов (get_day_details, get_workouts) и plan_generator.

### class ChatContextBuilder — L10
Не наследует BaseService (свой $db). Static-свойство `$RUNNING_PLAN_TYPES` L932 (private) — список беговых типов плана.

#### `__construct($db)` — L14
Сохраняет mysqli.

#### `formatPlanHistoryAnalyses(int $userId)` — L23 (private)
Блок «ИСТОРИЯ ТРЕНИРОВОК»: строки план→факт, недельный rollup и ключевые тренировки из WorkoutAnalysisRepository (getSummaryLinesForActivePlan / getWeeklyRollupForActivePlan / getKeyWorkoutSummaryForActivePlan). Ошибки глотает (''). Прим.: docblock над методом (L18-22) на деле описывает buildContextForUser.

#### `buildContextForUser(int $userId)` — L60
Главный метод: getUserData + loadTrainingPlanForUser + getStats + память (`chat_user_memory`) → склейка секций: профиль, оценка плана генератором, текущая неделя плана, статистика, coaching insights, последние тренировки, история, wellness, память, суммаризация истории. Вызывается ChatService и plan_generator.

#### `getUserMemory(int $userId)` — L93 (private)
SELECT content FROM `chat_user_memory`.

#### `getHistorySummary(int $userId)` — L106
SELECT history_summary FROM `chat_user_memory` (суммаризация старой истории чата).

#### `setHistorySummary(int $userId, string $content)` — L120
INSERT ... ON DUPLICATE KEY UPDATE history_summary в `chat_user_memory`. Вызывается ChatService (суммаризация, clearAiChat).

#### `formatProfile(?array $user)` — L132 (private)
Блок «ПРОФИЛЬ» + «ЦЕЛЬ»: пол/возраст/рост/вес/уровень/объём, ветвление по goal_type (race/time_improvement/weight_loss/health), темп, дни бега, время тренировок, дорожка, health_notes.

#### `formatTimeForPrompt(?string $time)` — L239 (private)
HH:MM[:SS] → «X ч YY мин» (защита от неоднозначного чтения моделью).

#### `formatPlanSummary(array $plan, int $userId)` — L248 (private)
Блок «ТЕКУЩИЙ ПЛАН»: находит неделю, в которую попадает «сегодня» (TZ юзера), выводит номер/объём и дни с типами по-русски.

#### `getUserTz(int $userId)` — L302 (private)
DateTimeZone юзера, fallback Europe/Moscow (дубль такого же helper'а в ChatConfirmationHandler).

#### `getDayTypeRu(string $type)` — L310 (private)
Маппинг типа дня (easy/long/tempo/...) на русское название.

#### `getStats(int $userId)` — L328 (private)
Выполнение плана: total дней (StatsRepository::getTotalDays), completed = union ключей из getCompletedDaysKeys + workout-даты, сопоставленные через findTrainingDay (training_utils). Ошибки → нули.

#### `formatStats(array $stats)` — L352 (private)
Блок «СТАТИСТИКА»: «Выполнено X из Y (Z%)».

#### `formatLatestPlanGeneratorSummary(?array $user)` — L374 (private)
Блок «ОЦЕНКА ПЛАНА ОТ ТРЕНЕРА» из users.last_plan_summary / last_plan_risk_review_json / last_plan_generated_at; поддерживает старый (массив рисков) и новый ({risk_review, critique}) форматы, выводит severity/issues/strengths самопроверки.

#### `formatRecentActivity(int $userId)` — L439 (private)
Блок «ПОСЛЕДНИЕ ТРЕНИРОВКИ» (3 шт.: дистанция/темп/HR/RPE/заметка) + «Дней без отдыха подряд» (daysSinceLastRest).

#### `formatRecentWellness(int $userId)` — L475 (private)
Блок «САМОЧУВСТВИЕ»: до 3 записей из `daily_wellness` за 7 дней (сон/настроение/энергия/стресс/soreness/RPE); сегодняшняя помечается временем фиксации.

#### `daysSinceLastRest(int $userId)` — L511 (private)
SQL по `training_plan_days` (type='rest') + дни без активности в `workout_log`/`workouts` за 14 дней → MAX(дата отдыха) → diff с сегодня.

#### `getRecentWorkouts(int $userId, int $limit)` — L541
UNION: `workout_log` (is_completed=1, join activity_types/training_plan_days) + `workouts` (импорт, дедуп по наличию workout_log за тот же день), сортировка по дате убыв. Используется также plan_generator.

#### `formatCoachingInsights(int $userId)` — L626 (private)
Блок «ТРЕНЕРСКАЯ СВОДКА»: последняя тренировка (+предупреждения о паузе >= 4 дн и тяжёлой), неделя (count/km/by_type), несовпадения план-vs-факт, compliance за 2 недели, тренд нагрузки, ACWR с зонами и подсказка про get_day_details.

#### `calculateACWR(int $userId)` — L748
Acute:Chronic Workload Ratio: нагрузка sRPE = duration × (6−rating)/5, либо дистанция×6 как proxy; acute=7 дн, chronic=среднее за 4 недели; зоны low/<0.8, optimal/<=1.3, caution/<=1.5, danger. Читает `workout_log`+`workouts`. Используется также plan_generator.

#### `getWeeklyCompliance(int $userId)` — L829
За 14 дней: planned (не-rest дни из `training_plan_days`+`training_plan_weeks`) vs completed (`workout_log` + дедуплицированные `workouts`); missed = разница. Используется также plan_generator.

#### `getLoadTrend(int $userId)` — L887 (private)
Процент изменения км этой недели против прошлой (workout_log + workouts через замыкание $getKm); null, если прошлая неделя < 1 км.

#### `getThisWeekWorkoutCount(int $userId)` — L937 (private)
Текущая неделя: тренировки/км по типам активности из `workouts` (GROUP BY) + ручные `workout_log` без дубля.

#### `getThisWeekPlanVsActual(int $userId)` — L1001 (private)
Сопоставление по дням текущей недели (до сегодня): match/mismatch (по плану бег, по факту нет running)/missing со списком mismatches.

#### `getDayDetails(int $userId, string $date)` — L1105
Детали дня: план из `training_plan_days` + упражнения `training_day_exercises` + факты из `workout_log` и `workouts`; при нескольких тренировках — массив workouts, workout = самая длинная. Бекенд тула get_day_details (ChatToolRegistry).

#### `getWorkoutsHistory(int $userId, string $dateFrom, string $dateTo, int $limit)` — L1204
История за период: UNION workout_log + workouts (почти тот же SQL, что getRecentWorkouts, но с датами и ASC, без дедупликации workouts). Бекенд тула get_workouts; используется plan_generator.

## `planrun-backend/services/ChatMediaService.php` (135 строк)
Хранение и раздача вложений чата (фото и голосовые) в /uploads/chat/. Все методы статические, без БД.

### class ChatMediaService — L8
Константы (private): `SUBDIR` L9, `FILE_PATTERN` L10, `MAX_BYTES` L11 (16 МБ), `IMAGE_MIME_EXT` L12, `AUDIO_TYPE_EXT` L19 (тип объявляет клиент), `EXT_MIME` L26.

#### `dir()` — L33 (static)
Абсолютный путь `<root>/uploads/chat/`.

#### `isValidFileName(string $name)` — L37 (static)
Соответствие FILE_PATTERN (chat_<uid>_<ts>_<8hex>.<ext>).

#### `store(int $userId, array $file)` — L45 (static)
Сохраняет upload: изображение валидируется через getimagesize (mime → ext, w/h), иначе аудио по заявленному Content-Type (отрезая «; codecs=»); создаёт каталог, move_uploaded_file, chmod 0644. Возвращает {file, kind, w, h}. Исключения при ошибках.

#### `serveRequested(string $requestedFile)` — L98 (static)
Раздаёт файл по валидному имени: Content-Type по расширению, immutable-кэш на год, readfile. false при невалидном/отсутствующем.

#### `sanitizeAttachment($attachment)` — L120 (static)
Нормализует дескриптор вложения от клиента: доверяет только валидному имени реально существующего файла; для audio — duration, для image — w/h. null при невалидном.

## `planrun-backend/services/ChatMemoryManager.php` (259 строк)
Долговременная память AI-тренера: LLM извлекает факты из диалога, мержит с памятью (дедуп по словам), компрессия по лимиту 2000 символов, хранение в `chat_user_memory.content`.

### class ChatMemoryManager — L12
Константы (private): `MAX_MEMORY_LENGTH` L18 (2000), `MAX_FACTS_PER_EXTRACTION` L19 (10), `MIN_MESSAGES_FOR_EXTRACTION` L20 (4).

#### `__construct($db)` — L22
Читает env LLM_CHAT_BASE_URL / LLM_CHAT_MODEL.

#### `extractAndSaveMemory(int $userId, array $recentMessages)` — L32
Главный метод: при >= 4 сообщениях извлекает факты LLM'ом (extractFacts, без лока), затем под MySQL advisory-локом GET_LOCK (#21, против конкурентных экстракций) перечитывает память, мержит и сохраняет. Вызывается из ChatService::triggerMemoryExtraction.

#### `acquireLock(string $name, int $timeoutSeconds)` — L57 (private)
SELECT GET_LOCK(?, ?); false при ошибке.

#### `releaseLock(string $name)` — L71 (private)
SELECT RELEASE_LOCK(?); ошибки глотает.

#### `extractFacts(array $messages, string $existingMemory, int $userId)` — L87 (private)
Собирает диалог (последние 20 сообщений, до 8000 симв.), шлёт LLM-запрос (LlmGateway::requestChatCompletion, surface=chat_memory, temp 0.1, max_tokens 600) с промптом по 6 категориям; парсит строки `[КАТЕГОРИЯ] факт` (до 10). [] при «ПУСТО»/ошибке.

#### `mergeFacts(string $existingMemory, array $newFacts)` — L176 (private)
Построчный мердж с дедупликацией через isSimilarFact; при превышении лимита — compressMemory.

#### `isSimilarFact(string $a, string $b)` — L207 (private)
>60% общих значимых слов (>= 3 символа, минус стоп-слова) относительно более короткого факта.

#### `compressMemory(string $memory)` — L225 (private)
Удаляет самые старые (верхние) строки, пока длина > 2000 и строк > 5.

#### `getMemory(int $userId)` — L235
SELECT content FROM `chat_user_memory` (дублирует приватный ChatContextBuilder::getUserMemory).

#### `saveMemory(int $userId, string $content)` — L245
UPSERT content в `chat_user_memory`; Logger::info при успехе.

## `planrun-backend/services/ChatPromptBuilder.php` (562 строки)
Сборка system prompt и массива сообщений для LLM-чата (DeepSeek): бюджетирование токенов, intent-аддоны (забег/добавление тренировки), нормализация чередования ролей, RAG и поиск по истории. Стабильный префикс промпта для KV-cache (даты — в конец).

### class ChatPromptBuilder — L12
Константы (private): `MAX_CONTEXT_TOKENS` L14 (32000), `SYSTEM_PROMPT_BUDGET` L15 (6000, в коде НЕ используется), `CONTEXT_BUDGET` L16 (10000), `HISTORY_BUDGET` L17 (14000), `CHARS_PER_TOKEN` L18 (3.2).

#### `__construct($db, ChatContextBuilder $contextBuilder, ChatRepository $repository)` — L25
Сохраняет зависимости.

#### `estimateTokens(string $text)` — L35
Оценка токенов: ceil(длина / 3.2) — эмпирика для кириллицы.

#### `estimateMessagesTokens(array $messages)` — L44
Сумма estimateTokens по сообщениям + 4 на каждое.

#### `buildChatMessages(int $userId, string $context, array $history, string $currentQuestion)` — L56
Главный метод: system = сжатый промпт (+аддоны при intent забега/добавления) + контекст (бюджет 10K); история → user/assistant; в последний user-вопрос добавляются resolved-дата и блок DATES (а не в system — ради DeepSeek prefix cache); тримминг истории (14K) и общий (32K); финал — normalizeMessagesForStrictAlternation.

#### `buildCompressedSystemPrompt(int $userId, string $today, string $tomorrow, string $todayDow, string $tomorrowDow)` — L118 (private)
Статический heredoc-промпт PlanRun-тренера (стиль, 100% русский, правила tools/подтверждений/точности цифр). Локальные $yesterday* вычисляются, но не используются (остаток рефакторинга).

#### `buildDatesSuffix(string $today, string $tomorrow, string $todayDow, string $tomorrowDow)` — L163 (private)
Мутабельный блок «DATES: вчера/сегодня/завтра» — добавляется последним, чтобы не ломать prefix cache.

#### `getRaceReplacementAddon(string $today, string $tomorrow)` — L172 (private)
Аддон-инструкция замены тренировок на забег + тактика.

#### `getAddTrainingAddon(?string $resolvedDate)` — L176 (private)
Аддон-инструкция добавления тренировки: типы, форматы description, вычисленная дата.

#### `trimToTokenBudget(string $text, int $maxTokens)` — L193 (private)
Обрезка текста по параграфам до бюджета; если первый параграф больше бюджета — жёсткая обрезка по символам.

#### `trimHistoryToTokenBudget(array $messages, int $maxTokens)` — L217 (private)
Удаляет старые сообщения с начала, пока > бюджета (минимум 2 остаются).

#### `trimOldestMessages(array $messages, int $excessTokens)` — L234 (private)
Удаляет старейшие non-system сообщения суммарно на excess токенов.

#### `normalizeMessagesForStrictAlternation(array $messages)` — L259
Приводит историю к строгому чередованию user/assistant: ведущие assistant-сообщения уходят в system-«ПРЕДЫДУЩИЕ СООБЩЕНИЯ АССИСТЕНТА», подряд идущие одинаковые роли склеиваются с разделителями. Public (тестируется напрямую).

#### `appendChatSearchSnippet(string $context, int $conversationId, string $currentMessage)` — L333
Keyword-поиск по истории текущего диалога (ChatRepository::searchInChat, до 8 слов/8 строк) → блок «ИЗ ПРОШЛЫХ СООБЩЕНИЙ». Флаг CHAT_SEARCH_HISTORY (default 1).

#### `appendRagSnippet(string $context, string $currentMessage)` — L376
RAG: POST на /api/v1/retrieve-knowledge (PlanRun AI, curl, timeout 15с) → блок «БАЗА ЗНАНИЙ»; sources кэшируются по md5 запроса (#32, Cache, TTL CHAT_RAG_CACHE_TTL_SECONDS default 3600). Флаг CHAT_RAG_ENABLED (default 0).

#### `hasAddTrainingIntent(string $currentQuestion, array $history)` — L454
Интент добавления тренировки по глаголам («добавь», «поставь»...) в текущем вопросе + 3 последних user-сообщениях; исключает rest-фразы («день отдыха», «отмени»...).

#### `hasReplaceWithRaceIntent(string $currentQuestion, array $history)` — L488
Интент замены на забег: упоминание дистанции (марафон/21.1/...) И (глагол замены ИЛИ слово «тактика/план»).

#### `resolveDateFromUserMessage(string $text, DateTime $now)` — L529
DateResolver: если в тексте есть ссылка на дату — резолвит в Y-m-d, иначе null.

#### `enrichQuestionWithDates(string $question, DateTime $now)` — L543 (private)
Если в вопросе есть дата-референс — дописывает «[система: дата=Y-m-d, дов]».

## `planrun-backend/services/ChatService.php` (1039 строк)
Оркестратор чата: LLM-вызовы (DeepSeek напрямую или PlanRun AI fallback), streaming NDJSON, native tool calling loop, CRUD сообщений (ai/admin/direct), push/in-app уведомления, суммаризация истории, триггер памяти.

### class ChatService — L25
Наследует BaseService. Константы (private): `DEFAULT_HISTORY_LIMIT` L27 (100), `DEFAULT_SUMMARIZE_THRESHOLD` L28 (35), `DEFAULT_RECENT_MESSAGES` L29 (15).

#### `__construct($db)` — L43
Создаёт ChatRepository, ChatContextBuilder, ChatToolRegistry, ChatPromptBuilder, ChatConfirmationHandler, ChatActionParser, ChatMemoryManager; читает env LLM_CHAT_BASE_URL/LLM_CHAT_MODEL/CHAT_HISTORY_MESSAGES_LIMIT.

#### `tryHandlePostWorkoutFollowupReply(int $userId, int $conversationId, int $userMessageId, string $content)` — L61 (private)
Делегирует PostWorkoutFollowupService::tryHandleUserReply (детерминированный ответ на чек-ин после тренировки); ошибки → warning + null.

#### `persistPostWorkoutFollowupReply(int $userId, int $conversationId, array $followupReply)` — L79 (private)
Сохраняет AI-ответ followup в `chat_messages` (через repository), touch разговора, триггерит память, шлёт уведомление coach.proactive_post_workout_checkin_reply.

#### `checkLlmHealth()` — L112
GET {base}/models (curl, 3с); требует непустой список моделей. Положительный результат кэшируется (#4, Cache, TTL CHAT_HEALTH_CACHE_TTL_SECONDS default 30с), негатив не кэшируется.

#### `applyHistorySummarization(int $userId, int $conversationId, array &$history)` — L159 (private)
При >= CHAT_SUMMARIZE_THRESHOLD (35) сообщений: суммаризирует старые (кроме последних 15), пишет через ChatContextBuilder::setHistorySummary и отрезает их из $history. Флаг CHAT_SUMMARIZE_ENABLED.

#### `summarizeOlderMessages(array $messages, int $userId)` — L175 (private)
LLM-суммаризация диалога (до 12000 симв., max_tokens 800) по категориям ЦЕЛИ/ТРАВМЫ/ПРИВЫЧКИ/РЕШЕНИЯ. '' при ошибке.

#### `sendMessageAndGetResponse(int $userId, string $content, ?array $attachment)` — L221
Non-streaming путь (fallback): сохраняет user-сообщение (+attachment в metadata), обрабатывает followup-ответ, суммаризацию, строит контекст (+search/RAG) и messages; vision-картинка по флагу CHAT_VISION_ENABLED; health-check; callLlm с tool loop; sanitize+parse; сохраняет AI-ответ; push при connection_aborted; память. Confirmation handlers сюда намеренно не включены (#2 — риск двойного исполнения тулов).

#### `streamResponse(int $userId, string $content)` — L292
Streaming путь (NDJSON в echo): user-сообщение → followup → суммаризация → контекст (+search/RAG) → confirmation handlers (swap → race → generic) → иначе health-check + resolveToolCalls → контрольные строки plan_updated/plan_recalculating/plan_generating_next → стрим с буферизацией [THINK]/<think>-тегов (inline-колбэк режет reasoning на лету) → sanitize → сохранение AI-сообщения с tools_used → push при обрыве → память.

#### `triggerMemoryExtraction(int $userId, int $conversationId)` — L435 (private)
Читает 20 последних сообщений и зовёт ChatMemoryManager::extractAndSaveMemory. Флаг CHAT_MEMORY_ENABLED; ошибки non-blocking.

#### `resolveToolCalls(array $messages, int $userId, array &$toolsUsed)` — L447 (private)
Pre-stream tool loop (до 5 раундов): callLlmDirect с tools; при tool_calls — эмитит `{"tool_executing"}` в стрим, исполняет через ChatToolRegistry::executeTool, дописывает assistant/tool-сообщения. Флаг CHAT_TOOLS_ENABLED.

#### `attachImageToLastUserMessage(array $messages, array $attachment)` — L502 (private)
Преобразует последнее user-сообщение в multimodal-массив [text, image_url] с base64 data-URL из файла ChatMediaService.

#### `callLlm(array $messages, ?int $userId)` — L528 (private)
Non-streaming LLM с tool loop (CHAT_MAX_TOOL_ROUNDS, default 3): callLlmDirect, исполнение тулов (вывод обрезается до CHAT_TOOL_RESULT_MAX_BYTES), логирование раундов/таймингов. CHAT_USE_PLANRUN_AI=1 → callPlanRunAIChat; при исключении и CHAT_FALLBACK_TO_PLANRUN_AI=1 — fallback.

#### `callLlmDirect(array $messages, ?array $tools, ?int $userId)` — L601 (private)
Один вызов chat/completions через LlmGateway::requestChatCompletion (timeout 120с, retries LLM_MAX_RETRIES, observability surface=chat). Возвращает {content, message, usage}.

#### `callPlanRunAIChat(array $messages)` — L625 (private)
HTTP POST на PlanRun AI /api/v1/chat (curl, 120с); Exception при недоступности.

#### `callLlmStream(array $messages, callable $onChunk, ?int $userId)` — L641 (private)
Маршрутизация стрима: PlanRun AI или callLlmStreamDirect с fallback по флагам.

#### `callLlmStreamDirect(array $messages, callable $onChunk, ?int $userId)` — L658 (private)
SSE-стрим chat/completions: concurrency lease (LlmGateway::acquireConcurrencyLease), до LLM_MAX_RETRIES попыток с backoff (retryable: 429/5xx; не ретраит после первых чанков), парсинг `data:`-строк в CURLOPT_WRITEFUNCTION, учёт finish_reason/чанков/символов; в finally — release lease + logLlmStreamEvent.

#### `logLlmStreamEvent(string $traceId, ?int $userId, string $status, array $payload, int $durationMs)` — L776 (private)
Пишет событие llm_stream в AiObservabilityService (surface=chat); ошибки глотает.

#### `callPlanRunAIChatStream(array $messages, callable $onChunk)` — L791 (private)
Стрим с PlanRun AI: NDJSON-строки {chunk} в onChunk; Exception при ошибке.

#### `getLatestProactiveMessage(int $userId, string $proactiveType, int $sinceHours)` — L826
Последний proactive-месседж (briefing/insight) из repository за окно — для hero-card дашборда.

#### `getMessages(int $userId, string $type, int $limit, int $offset)` — L836
Сообщения разговора (admin-таб через getAdminTabMessages); для type='ai' добавляет pending_ai_response (state незавершённого стрима, TTL CHAT_PENDING_RESPONSE_TTL_SECONDS).

#### `clearAiChat(int $userId)` — L853
Удаляет сообщения AI-разговора + обнуляет history_summary.

#### `clearAdminChat(int $userId)` — L859
Удаляет сообщения admin-разговора пользователя.

#### `markAsRead(int $userId, int $conversationId)` — L864
Помечает сообщения разговора прочитанными + NotificationService::markReadByRefKey('chat:<id>').

#### `sendUserMessageToAdmin(int $userId, string $content, ?array $attachment)` — L873
Сообщение юзера в admin-чат: addMessage + уведомления всем админам (notifyAdminsAboutUserMessage).

#### `sendUserMessageToUser(int $senderUserId, int $targetUserId, string $content, ?array $attachment)` — L884
Direct-сообщение (хранится в admin-разговоре получателя с sender_id отправителя) + push chat.direct_message. Запрет себе.

#### `sendAdminMessage(int $targetUserId, int $adminUserId, string $content)` — L896
Сообщение от админа юзеру + push chat.admin_message.

#### `getDirectMessagesWithUser(int $currentUserId, int $targetUserId, int $limit, int $offset)` — L904
Direct-история между двумя юзерами; попутно помечает диалог прочитанным.

#### `clearDirectDialog(int $currentUserId, int $targetUserId)` — L909
Удаляет direct-сообщения между двумя юзерами; возвращает количество.

#### `getAdminMessages(int $targetUserId, int $limit, int $offset)` — L914
Admin-вид переписки с юзером; помечает user-сообщения прочитанными админом.

#### `getUsersWithAdminChat()` — L920
Прокси repository: список юзеров с admin-чатом.

#### `getUsersWhoWroteToMe(int $userId)` — L921
Прокси repository: кто писал юзеру direct.

#### `getUnreadUserMessagesForAdmin(int $limit)` — L922
Прокси repository: непрочитанные user-сообщения для админ-меню.

#### `getAdminUnreadCount()` — L923
Прокси repository: счётчик непрочитанного для админа. Внешних вызовов не найдено (кандидат dead).

#### `markAllAsRead(int $userId)` — L924
Прокси repository: пометить все разговоры юзера прочитанными.

#### `markAllAdminAsRead()` — L925
Прокси repository: пометить все user-сообщения прочитанными админом.

#### `markAdminConversationRead(int $targetUserId)` — L927
Пометить admin-разговор конкретного юзера прочитанным.

#### `addAIMessageToUser(int $userId, string $content, array $notificationOptions)` — L932
Программная вставка AI-сообщения (proactive: брифинги, чек-ины; до 4000 симв.) + уведомление через dispatchNotificationEvent (event_key/title/link настраиваемые).

#### `sendChatPush(int $userId, string $title, string $body, string $type, array $notificationOptions)` — L955 (private)
Маппит тип (admin/ai/direct) на event_key и зовёт dispatchNotificationEvent; ошибки глотает.

#### `dispatchNotificationEvent(int $userId, string $eventKey, string $title, string $body, string $link, array $notificationOptions)` — L962 (private)
Push через NotificationDispatcher::dispatchToUser (body обрезается до 100) + in-app запись NotificationService::create со свёрнутым ref_key 'chat:<conversationId>' (dispatch=false).

#### `notifyAdminsAboutUserMessage(int $senderUserId, string $senderName, string $content)` — L994 (private)
Рассылает admin.new_user_message всем админам (кроме отправителя).

#### `getUsernameById(int $userId)` — L1009 (private)
SELECT username FROM `users`.

#### `getAdminUserIds(int)` — L1018 (private)
SELECT id FROM `users` WHERE role='admin'.

#### `broadcastAdminMessage(int $adminUserId, string $content, ?array $userIds)` — L1026
Рассылка admin-сообщения списку юзеров (или всем через getAllUserIdsForBroadcast) с push; возвращает {sent}.
