# Frontend: utils и hooks — справочник функций

## `src/hooks/useAppUpdateCheck.js` (94 строки)
Хук проверки наличия новой версии APK для нативного Capacitor-приложения: сравнивает `version_code` из манифеста `https://planrun.ru/version.json` с build-номером установленного приложения.

### `useAppUpdateCheck(enabled = true)` — L6
Хук. Возвращает `{ updateAvailable, updateInfo, dismissUpdate, checkForApkUpdate }`. На нативе: одноразовая проверка через 800 мс после монтирования + подписка на `appStateChange` (повторная проверка при возврате приложения в foreground). На вебе/при `enabled=false` — ничего не делает. Внутренний `checkForApkUpdate` (L11) фетчит манифест с cache-bust, парсит `version_code`, сравнивает с `App.getInfo().build`; при превышении и отсутствии dismissed — кладёт `updateInfo` (с фолбэком `download_url` на `planrun.ru/downloads/planrun-<version>.apk`); все сетевые ошибки молча глотает. Внутренний `dismissUpdate` (L83) запоминает отклонённый `version_code` в ref и сбрасывает `updateInfo`.

## `src/hooks/useChatUnread.js` (38 строк)
Real-time счётчик непрочитанных сообщений чата через SSE-сервис `ChatSSE`.

### `sameUnread(a, b)` — L10
Внутренняя. Глубокое сравнение двух unread-объектов `{ total, by_type }`: сравнивает `total`, набор ключей и значения `by_type`. Возвращает boolean; используется для подавления лишних ре-рендеров.

### `useChatUnread()` — L23
Хук. Возвращает текущий объект непрочитанных `{ total, by_type: { admin, ai, coach, direct, ... } }`. Инициализируется из `ChatSSE.getUnreadData()`, подписывается на `ChatSSE.subscribe`, отписывается при размонтировании; обновляет state только если payload реально изменился (через `sameUnread`).

## `src/hooks/useIsTabActive.js` (7 строк)
Определение активной вкладки нижней навигации по текущему URL.

### `useIsTabActive(path)` — L3
Хук. Возвращает boolean: активен ли таб с данным path. Подписан на `useLocation()` (react-router). Для `'/'` — точное совпадение с `/` или `/dashboard`, иначе — `pathname.startsWith(path)`.

## `src/hooks/useMediaQuery.js` (23 строки)
Реактивная подписка на CSS media query.

### `useMediaQuery(query)` — L8
Хук. Принимает строку media query (например `'(max-width: 768px)'`), возвращает boolean `matches`. Лениво инициализируется через `window.matchMedia` (SSR-safe: `false` без window), подписывается на событие `change` MediaQueryList, отписывается в cleanup.

## `src/hooks/useMobileKeyboardState.js` (161 строка)
Детектор открытия экранной клавиатуры на мобильных (< 1024px). Отдаёт только boolean-флаг (не высоту) — размер чата держит CSS `100dvh`, чтобы не было «прыжков».

### `isTextEntryElement(element)` — L17
Внутренняя. Проверяет, является ли элемент полем текстового ввода: `textarea`, `input` текстовых типов (из `TEXT_INPUT_TYPES`, L7) не disabled/readOnly, либо contentEditable. Возвращает boolean.

### `getViewportMetrics()` — L34
Внутренняя. Считает по `window.visualViewport` и `window.innerHeight` объект `{ visibleHeight, baselineHeight }` (видимая высота с учётом offsetTop и базовая высота вьюпорта).

### `useMobileKeyboardState({ enabled = true, minKeyboardHeight = 120 } = {})` — L50 (default export)
Хук. Возвращает `{ isKeyboardOpen: boolean }`. Подписки: `focusin`/`focusout` документа (с дебаунсом 60 мс), `resize`/`orientationchange` окна, `resize`/`scroll` visualViewport; на нативе дополнительно Capacitor `Keyboard.keyboardDidShow/keyboardDidHide` (ref-ы `nativeKeyboardOpenRef`/`nativeKeyboardHeightRef`). Внутренний `updateState` (L75) через rAF: клавиатура считается открытой, если вьюпорт ≤ 1023px И (нативная клавиатура показана ИЛИ есть сфокусированный текстовый инпут и перекрытая высота ≥ `minKeyboardHeight`). State меняется только при флипе boolean. Cleanup снимает все слушатели и нативные listener-handles.

## `src/hooks/usePasswordResetRequest.js` (67 строк)
Флоу запроса сброса пароля по email/логину с обработкой rate-limit.

### `usePasswordResetRequest()` — L6
Хук. Возвращает `{ loading, error, setError, sent, sentToEmail, isCoolingDown, secondsLeft, requestReset, resetState }`. Внутренний `resetState` (L13) сбрасывает error/sent/sentToEmail. Внутренний `requestReset(identifier)` (L19): валидирует непустой ввод, блокируется при активном cooldown, вызывает `getAuthClient().requestResetPassword(trimmed)`; при успехе ставит `sent`/`sentToEmail`, при ошибке — сообщение через `getAuthErrorMessage` и запускает cooldown по `getAuthRetryAfter`. Возвращает `{ success, ... }`.

## `src/hooks/useRetryCooldown.js` (50 строк)
Секундный обратный отсчёт для блокировки повторных запросов (rate-limit UI).

### `useRetryCooldown()` — L3
Хук. Возвращает `{ secondsLeft, isCoolingDown, startCooldown, clearCooldown }`. Внутренний `clearCooldown` (L7) гасит interval и обнуляет счётчик; `startCooldown(seconds)` (L15) нормализует значение (ceil, ≥0), ставит `setInterval` с декрементом раз в секунду до 0. Cleanup-эффект чистит interval при размонтировании.

## `src/hooks/useSwipeableTabs.js` (132 строки)
Горизонтальные свайпы по touch-событиям для переключения вкладок внутри контейнера.

### `shouldIgnoreSwipeTarget(target, ignoreSelector)` — L15
Внутренняя. Возвращает true, если цель тача находится внутри элемента, совпадающего с `ignoreSelector` (по умолчанию инпуты, textarea, select, contenteditable, `[data-swipe-lock="true"]` — const `DEFAULT_IGNORE_SELECTOR` L3).

### `useSwipeableTabs({ containerRef, tabs, activeTab, onTabChange, enabled = true, ignoreSelector })` — L19
Хук без возвращаемого значения. Вешает на `containerRef.current` слушатели `touchstart/touchmove/touchend/touchcancel`. Логика: ось свайпа фиксируется после 12px (`MIN_AXIS_LOCK_DISTANCE` L11) при горизонтальном преобладании ×1.1 (`SWIPE_RATIO` L13); горизонтальный свайп вызывает `preventDefault` на move; на end при |ΔX| ≥ 56px (`MIN_SWIPE_DISTANCE` L12) переключает на соседний таб через `onTabChange`. Актуальные `tabs/activeTab/onTabChange` держит в ref-ах, чтобы не пересоздавать слушатели. Отключается при `enabled=false` или `tabs.length < 2`.

## `src/hooks/useVerificationCodeFlow.js` (63 строки)
Состояние шага «ввод кода подтверждения» при регистрации: шаг формы/кода, попытки, cooldown, обработка ошибок.

### `useVerificationCodeFlow({ onError } = {})` — L5
Хук. Возвращает `{ verificationStep, setVerificationStep, verificationCode, setVerificationCode, codeAttemptsLeft, setCodeAttemptsLeft, isCoolingDown, secondsLeft, startCooldown, handleRequestError, handleConfirmError, markCodeSent, resetFlow }`. Внутренние колбэки: `setFlowError` (L11) проксирует в `onError`; `applyRetryAfter` (L15) запускает cooldown из `getAuthRetryAfter(err)`; `handleRequestError` (L20) — ошибка отправки кода; `handleConfirmError` (L25) — ошибка подтверждения (дополнительно обновляет `attempts_left` из ошибки); `markCodeSent` (L33) переводит на шаг 'code' и сбрасывает код/попытки/ошибку; `resetFlow` (L40) возвращает на шаг 'form'.

## `src/utils/androidInsets.js` (31 строка)
Детект Android edge-to-edge режима, когда `env(safe-area-inset-top)` равен 0, и проставление CSS-класса для ручной компенсации.

### `detectAndroidEdgeToEdge()` — L1 (named + default export)
Только для Android UA, иначе no-op. Внутренняя `apply` (L9): измеряет реальный `env(safe-area-inset-top)` через невидимый probe-div, эвристика edge-to-edge (`screen.height - innerHeight < 40`); если env ≈ 0 и (Android ≥ 13 или looksEdgeToEdge) — добавляет класс `android-e2e` на `<html>`. Вызывается сразу (или на DOMContentLoaded) и повторно через 250 мс после `orientationchange`.

## `src/utils/appUpdate.js` (79 строк)
Поллинг новой web-сборки (SPA): сравнивает `buildId` из `/build-info.json` с вшитым `VITE_APP_BUILD_ID` и перезагружает страницу один раз на новый билд.

### `fetchLatestBuildId(signal)` — L5
Внутренняя async. GET `/build-info.json` с cache-bust и `no-store`; возвращает строку `buildId` либо null (не ok / невалидный JSON).

### `startAppUpdatePolling()` — L20
Экспорт. Только в PROD и при наличии window (иначе возвращает no-op cleanup). Каждые 5 мин (`UPDATE_CHECK_INTERVAL_MS` L2) плюс на `visibilitychange(visible)`/`focus`/`pageshow` проверяет buildId; при отличии ставит маркер `planrun-reloaded-build:<id>` в sessionStorage (защита от reload-петли) и делает `window.location.reload()`. Предыдущий fetch абортится через AbortController. Возвращает функцию полной отписки.

## `src/utils/authError.js` (29 строк)
Извлечение человекочитаемого сообщения и retry-after из ошибок auth API.

### `getAuthErrorMessage(error, fallback)` — L1
Возвращает `error.message` либо fallback; при rate-limit (status 429 / code RATE_LIMITED) с известным retry_after дописывает «Подождите N сек.», если в сообщении ещё нет «через N сек».

### `getAuthRetryAfter(error)` — L15
Возвращает число секунд до повтора: из `error.retry_after` (ceil) либо парсит «через N сек» из текста сообщения; иначе 0.

## `src/utils/avatarUrl.js` (52 строки)
Построение URL аватара через бэкенд-экшен `get_avatar` (с вариантами sm/md/lg), поддержка внешних http(s)-URL.

### `getAvatarSrc(avatarPath, baseUrl = '/api', variant = 'full')` — L12
Возвращает строку URL или ''. Внешние `http(s)://` возвращает как есть. Из локального пути берёт имя файла и валидирует паттерн `avatar_<id>_<ts>[_hash].(jpg|png|gif|webp)` — иначе ''. Нормализует `apiRoot`; на Capacitor-нативе (origin `capacitor://`, `file://`, `https://localhost` или `Capacitor.isNativePlatform()`) переписывает относительный `/api` на абсолютный из `VITE_API_BASE_URL` (фолбэк `https://planrun.ru`). Итог: `<apiRoot>/api_wrapper.php?action=get_avatar&file=<name>[&variant=<v>]`.

## `src/utils/calendarHelpers.js` (440 строк)
Хелперы календаря тренировок (порт `components/calendar_helpers.php`): даты недели, CSS-классы типов, краткие описания, маппинг типов в категории активности, статус выполнения дня.

### `getDateForDay(startDate, dayOfWeek)` — L12
Возвращает дату (YYYY-MM-DD) дня недели `mon..sun` внутри недели, начинающейся с `startDate`. Воскресенье нормализует в 7; отрицательный сдвиг переносит на +7 дней.

### `getTrainingClass(type, isKey = false)` — L30
Возвращает CSS-класс по типу тренировки (rest→'rest-day', long/marathon→'long-run', sbu/race/fartlek→'interval' и т.д.); `control` всегда 'control'. Параметр `isKey` фактически не используется. Неизвестный тип → ''.

### `getShortDescription(fullText, type)` — L59
Возвращает HTML-строку краткого описания для ячейки календаря. Срезает HTML-теги, затем по типу: rest — «ОТДЫХ/ПОЛНЫЙ ОТДЫХ»; tempo/easy/long — извлекает регэкспами дистанцию («N КИЛОМЕТР»), пульс («Пульс: N-N») и темп («Темп: M:SS[-M:SS]»); interval — паттерн «NxM»; other (ОФП) — длительность; sbu — первые 50 символов; race — дистанция; free — «—»; default — первые 80 символов. Всё экранируется через `escapeHtml`.

### `escapeHtml(text)` — L270
Внутренняя. Экранирует HTML через DOM (`div.textContent` → `innerHTML`).

### `formatDateShort(dateString)` — L279
Возвращает дату в формате `ДД.ММ`.

### `getDayName(dayKey)` — L289
Возвращает русское сокращение дня недели ('mon'→'Пн' и т.д.), при неизвестном ключе — сам ключ.

### `planTypeToCategory(type)` — L306
Маппит тип дня плана в категорию активности: беговые типы (const `RUNNING_TYPES` L303: easy, long, long-run, tempo, interval, fartlek, control, race, run, running) → 'running'; walking/hiking/cycling/swimming/other/sbu → как есть; null при пустом входе; неизвестные — lowercase-строка.

### `workoutTypeToCategory(type)` — L320
То же для `activity_type` фактической тренировки; отличие от `planTypeToCategory` — при пустом входе возвращает 'running' (дефолт), а не null. Тела почти идентичны.

### `getPlanDayForDate(dateStr, planData)` — L339
Ищет в `planData.weeks_data` (или `phases[0].weeks_data`) неделю, содержащую дату, и возвращает `{ items, weekNumber, type, text, is_key_workout }` дня (поддерживает массив тренировок в дне) либо null.

### `getDayCompletionStatus(dateStr, planDayForDate, workoutsData, resultsData, workoutsListByDate, executedByDate)` — L379
Возвращает `{ status: 'completed'|'rest_extra'|'planned'|'rest', extraWorkoutType? }`. Собирает множество фактических категорий из 4 источников (списка тренировок, результатов, агрегата workoutsData, `executedByDate` где 'ofp'→'other', 'sbu'→'sbu') и сравнивает с категориями плановых не-rest/free тренировок: все покрыты → completed; план пуст, но активность есть → rest_extra; план пуст и активности нет → rest; иначе planned.

### `getPlanWeekCategories(week)` — L427
Возвращает Set категорий активности недели плана (по всем дням, исключая rest/free); при пустых днях — `Set(['running'])`. Потребителей в src/ не найдено (мёртвый экспорт).

## `src/utils/displayName.js` (38 строк)
Единый источник отображаемого имени пользователя (username — технический slug).

### `getDisplayName(u)` — L10
Возвращает «first_name last_name» → `name` → `username` → '' для любого объекта пользователя/атлета.

### `getFirstName(u)` — L23
Возвращает `first_name` либо первое слово отображаемого имени (для приветствий). Потребителей в src/ не найдено (мёртвый экспорт).

### `getInitials(u)` — L30
Возвращает 1-2 буквы инициалов для плейсхолдера аватара: первые буквы двух слов имени либо первые 2 символа; '?' если имени нет.

## `src/utils/durationMask.js` (83 строки)
Маска ввода длительности забега Ч:ММ:СС слева направо (замена `<input type="time">`, который на iOS не поддерживает секунды).

### `formatDurationMask(raw)` — L23
Форматирует произвольный ввод: берёт до 5 цифр (H MM SS), авто-вставляет двоеточия по мере набора, clamp минут/секунд ≤ 59. Возвращает строку вида «1», «1:35», «1:35:00».

### `normalizeDuration(formatted)` — L54
Приводит частичный ввод к каноничному `Ч:ММ:СС` для бэкенда (недостающие минуты/секунды → нули, clamp ≤ 59); '' при пустом входе.

### `durationToSeconds(formatted)` — L75
Возвращает секунды из строки Ч:ММ:СС / Ч:ММ / Ч (одиночное число трактуется как часы) либо null. Потребителей в src/ не найдено (мёртвый экспорт).

## `src/utils/haptics.js` (16 строк)
Тактильный отклик на нативе через `@capacitor/haptics`.

### `tapHaptic(style = 'light')` — L6 (named + default export)
На вебе no-op. На нативе лениво импортирует плагин (промис кэшируется в module-level `modPromise` L3) и вызывает `Haptics.impact` со стилем Light/Medium; все ошибки глотает.

## `src/utils/lapFormat.js` (57 строк)
Форматтеры кругов/длительности тренировки, общие для DayCompletedV3 и WorkoutDetailsModal. Возвращают null при невалидных данных.

### `formatLapDuration(totalSeconds)` — L4
Секунды → `H:MM:SS` или `M:SS` (округление до секунды); null если не число или ≤ 0.

### `formatWorkoutDuration(workout)` — L17
Из `workout.duration_seconds` → `H:MM:SS`/`M:SS`; иначе из `duration_minutes` → «Hч Mм»/«Mм»; null если нет данных.

### `formatLapDistance(distanceKm)` — L32
Км → «N м» (< 1 км) либо «X.XX км» (1 знак при ≥ 10 км, обрезка хвостовых нулей); null при невалидном.

### `getLapPaceSeconds(lap)` — L39
Возвращает темп круга в сек/км: явный `pace_seconds_per_km` → расчёт из `distance_km` и `moving_seconds|elapsed_seconds` → из `average_speed` (1000/v); null если нечего считать.

### `formatLapPace(lap)` — L52
Темп круга строкой `M:SS` на основе `getLapPaceSeconds`; null при отсутствии.

## `src/utils/lazyWithRetry.js` (44 строки)
`React.lazy` с авто-перезагрузкой страницы при ошибке загрузки чанка (устаревшая сборка после деплоя).

### `isChunkLoadError(error)` — L12
Возвращает true, если сообщение ошибки матчит один из паттернов chunk-load ошибок (`CHUNK_ERROR_PATTERNS` L4: ChunkLoadError, Loading chunk failed, Failed to fetch dynamically imported module и т.п.).

### `lazyWithRetry(importer, retryKey = 'module')` — L17 (named + default export)
Возвращает `React.lazy`-компонент. При успехе импорта чистит retry-маркер в sessionStorage (`planrun:lazy-retry:<key>`, префикс L3). При chunk-ошибке: если ещё не ретраили — ставит маркер, делает `location.reload()` и возвращает вечный pending-промис; если уже ретраили — снимает маркер и пробрасывает ошибку (попадёт в ErrorBoundary).

## `src/utils/logger.js` (122 строки)
Логгер: в браузере — только console; в Capacitor — console + запись в файл `planrun.log` (Directory.Cache) с обрезкой до ~200 KB.

### `initLogger()` — L17
Экспорт. На нативе лениво импортирует `@capacitor/filesystem` и кэширует Filesystem/Directory/Encoding в module-level переменные (L11–L14); промис сохраняется в `fsReady`. Вызывать при старте (main.jsx). На вебе no-op.

### `timestamp()` — L27
Внутренняя. ISO-строка текущего времени.

### `format(level, args)` — L31
Внутренняя. Формирует строку лога `[ts] [LEVEL] msg\n` (объекты — через JSON.stringify).

### `appendToFile(line)` — L36
Внутренняя async. Дописывает строку в `planrun.log` через `Filesystem.appendFile`; если файла нет — создаёт через `writeFile`. На вебе/без FS — no-op.

### `trimLogIfNeeded()` — L64
Внутренняя async. Если файл лога больше `MAX_LOG_BYTES` (L7, 200 KB) — перечитывает и оставляет хвост (~MAX/80 строк). Ошибки игнорирует.

### `logger` — L85 (const, named + default export)
Объект `{ log, warn, error }`: каждый метод дублирует в console и асинхронно пишет в файл с последующей обрезкой.

### `installGlobalErrorLogger()` — L107
Экспорт. Оборачивает `window.onerror` и `window.onunhandledrejection` (с сохранением и вызовом предыдущих обработчиков), логируя необработанные ошибки и rejection-ы через `logger.error`.

## `src/utils/modulePreloader.js` (54 строки)
Legacy-API предзагрузки экранов; основные вкладки теперь предзагружаются в AppTabsContent, здесь остался только UserProfileScreen.

### `runWhenIdle(callback, timeout = 1200)` — L8
Внутренняя. Запускает колбэк через `requestIdleCallback` (с timeout) либо фолбэк `setTimeout(min(timeout, 400))`.

### `preloadScreenModules()` — L25
Экспорт (arrow const). В idle-время импортирует `../screens/UserProfileScreen`. Снаружи не импортируется — используется только `preloadScreenModulesDelayed` в этом же файле.

### `preloadScreenModulesDelayed(delay = 300)` — L35
Экспорт (arrow const). Вызывает `preloadScreenModules` через setTimeout. Используется в App.jsx.

### `preloadAuthenticatedModules(role = 'user')` — L49
Экспорт (arrow const). То же самое (импорт UserProfileScreen в idle с timeout 1800); параметр `role` не используется. Используется в App.jsx.

## `src/utils/paceMask.js` (42 строки)
Маска ввода темпа M:SS / MM:SS (диапазон 3:00–10:00) для SettingsScreen и онбординга (StepProfile).

### `formatPaceMask(raw)` — L9
До 4 цифр; если первая цифра «1» — двузначные минуты (10:XX), иначе одиночные (3–9:XX); авто-двоеточие, clamp секунд ≤ 59. Возвращает форматированную строку.

### `paceMaskToSeconds(formatted)` — L38
Строка `M:SS`/`MM:SS` → секунды на км; null если формат не полный.

## `src/utils/planStatus.js` (7 строк)
Определение «план сейчас генерируется» по статусу очереди.

### `isActivePlanGenerationStatus(status)` — L3
Возвращает true, если `status.generating === true`, `status.queued === true` или `queue_status|status` ∈ {pending, running} (const `ACTIVE_PLAN_QUEUE_STATUSES` L1).

## `src/utils/shareImage.js` (154 строки)
Работа с изображениями для шеринга: выбор фото (камера/галерея/файл), canvas→blob, сохранение в галерею и шеринг через нативный шит / Web Share API.

### `pickPhotoDataUrl(source = 'photos')` — L3
Async. На нативе — `Camera.getPhoto` (source: camera/prompt/photos, DataUrl, quality 90, width 1600); при отмене — null. На вебе — создаёт скрытый `<input type="file" accept="image/*">` и читает выбранный файл в dataURL через FileReader. Возвращает dataURL-строку или null.

### `loadImage(src)` — L39
Возвращает Promise<HTMLImageElement> c `crossOrigin='anonymous'`; reject при пустом src или ошибке загрузки.

### `canvasToBlob(canvas, type = 'image/jpeg', quality = 0.92)` — L50
Промисификация `canvas.toBlob`; reject при недоступном canvas или пустом blob.

### `blobToBase64(blob)` — L63
Внутренняя. Blob → base64-строка (без data-URL префикса) через FileReader.

### `downloadBlob(blob, fileName)` — L76
Скачивает blob файлом через временный `<a download>` + ObjectURL (revoke через setTimeout 0). Снаружи не импортируется — используется только внутри файла (saveImageBlob, shareWeb).

### `saveImageBlob(blob, fileName)` — L91
Async, экспорт. На нативе сохраняет в галерею через плагин `../plugins/mediaSaver` (base64); при ошибке — фолбэк на `shareImageBlob` (системный шит). На вебе — `downloadBlob`. Возвращает `{ saved, cancelled }`.

### `shareNative(blob, fileName, title)` — L108
Внутренняя async. Пишет blob во временный файл (Filesystem, Directory.Cache) и открывает нативный шит `Share.share` с file URI. Возвращает `{ shared: true, cancelled: false }`.

### `shareWeb(blob, fileName, title)` — L121
Внутренняя async. Пробует `navigator.share` с File (с проверкой `canShare`); AbortError → `{ cancelled: true }`; иначе фолбэк на `downloadBlob`. Возвращает `{ shared, cancelled }`.

### `shareImageBlob(blob, fileName, title = 'PlanRun')` — L141 (named + default export)
Async. На нативе — `shareNative` (AbortError → cancelled, прочие ошибки → фолбэк на web-путь); на вебе — `shareWeb`. Возвращает `{ shared, cancelled }`.

## `src/utils/shareWorkoutModel.js` (81 строка)
Построение модели данных для генератора шеринг-картинки тренировки: метки типа, метрики, маршрут.

### `getActivityTypeLabel(workout)` — L10
Экспорт. Русская метка типа тренировки: сначала по plan-`type`, затем по `activity_type` из словаря `ACTIVITY_TYPE_LABELS` (L1, внутренний); фолбэк — сырое значение или «Тренировка». Снаружи не импортируется (есть локальный дубликат в WorkoutShareCard.jsx).

### `getRoutePoints(timeline)` — L19
Экспорт. Из массива точек timeline возвращает `[{ lat, lng }]`: фильтрует невалидные координаты, выход за диапазоны и нулевую точку (0,0). Снаружи не импортируется (дубликат в WorkoutShareCard.jsx).

### `fmtDuration(workout)` — L28
Внутренняя. Длительность из `duration_seconds`/`duration_minutes` → `H:MM:SS`/`M:SS`; null без данных. Дублирует логику `formatWorkoutDuration` из lapFormat.js (другой формат фолбэка минут).

### `num(v)` — L41
Внутренняя. Number(v), если конечное число, иначе null.

### `fmtDateRu(workout, date)` — L43
Внутренняя. Русская дата «5 июня» из `workout.start_time` либо из `date` (полдень, чтобы не словить TZ-сдвиг); '' при невалидной.

### `buildShareModel({ date, workout, timeline })` — L53 (named + default export)
Возвращает `{ typeLabel, dateStr, metrics, routePoints }` либо null без workout. `metrics` — массив `{ key, label, value, unit }` только реально доступных: дистанция, время, темп, пульс, макс. пульс, калории, набор высоты, каденс.

## `src/utils/structuredExercises.js` (116 строк)
Парсинг плоского текстового описания ОФП/СБУ в структурированный список упражнений.

### `parseStructuredExercises(text)` — L15
Срезает HTML, бьёт на строки, с каждой снимает префикс «ОФП:/СБУ:». Имя отделяется тире («Название — 4×12») либо позицией первого числа с ×/единицей. Из хвоста регэкспами извлекает: sets×дистанция (м/км), sets×«по N сек/мин/ч», sets×длительность, sets×reps (только если нет дистанции/длительности), вес (кг), одиночную длительность. Возвращает `[{ name, sets?, reps?, weight?, duration?, distance?, raw }]` или null, если ни одной строки не распарсилось.

### `groupExercisesByCategory(text)` — L100
Разносит строки по префиксам «ОФП:» / «СБУ:» / прочие и возвращает `{ ofp: [...], sbu: [...], other: string }` (ofp/sbu — через `parseStructuredExercises`, other — сырой текст).

## `src/utils/workoutFormUtils.js` (122 строки)
Общие утилиты форм тренировок (AddTrainingModal, ResultModal, WorkoutDetailsModal): парсинг/формат времени и темпа, маски ввода, словари типов и источников.

### `parseTime(timeStr)` — L10
`ЧЧ:ММ:СС` или `ММ:СС` → секунды; null при невалидном вводе (отрицательные, минуты/секунды ≥ 60).

### `formatTime(totalSeconds)` — L27
Секунды → всегда `Ч:ММ:СС` (с нулевыми часами тоже); '' при null/отрицательном.

### `parsePace(paceStr)` — L37
`ММ:СС` или одиночное число → минуты на км (float); null при невалидном.

### `formatPace(minutesPerKm)` — L53
Минуты на км (float) → `М:СС`; '' при null/≤0.

### `maskTimeInput(value)` — L63
Маска времени: до 6 цифр → `чч:мм:сс` поэтапно (после 2 цифр сразу дописывает двоеточие). Без clamp.

### `maskPaceInput(value)` — L75
Маска темпа: до 4 цифр → `М:СС`/`ММ:СС` («5»→«5», «53»→«5:3», «530»→«5:30»). Без clamp секунд (в отличие от `formatPaceMask` из paceMask.js).

### `RUN_TYPES` — L85 (const, экспорт)
Беговые типы плана: easy, tempo, long, long-run, interval, fartlek, control, race.

### `SIMPLE_RUN_TYPES` — L86 (const, экспорт)
Подмножество без структурных: easy, tempo, long, control, race.

### `TYPE_LABELS` — L88 (const, экспорт)
Алиас `WORKOUT_TYPE_LABEL` из workoutTypes.js.

### `ACTIVITY_TYPE_LABELS` — L90 (const, экспорт)
Тот же алиас `WORKOUT_TYPE_LABEL`. Снаружи не импортируется (в WorkoutShareCard.jsx — собственная локальная копия).

### `SOURCE_LABELS` — L92 (const, экспорт)
Словарь источников импорта: strava, huawei, polar, garmin, coros, gpx, fit. Снаружи не импортируется напрямую (дубликат-копия в WorkoutShareCard.jsx); используется через `getSourceLabel`.

### `getActivityTypeLabel(activityType)` — L102
Русская метка типа по словарю; фолбэк — сырое значение; '' при пустом.

### `getWorkoutDisplayType(workout)` — L109
Возвращает тип для отображения: plan-`type` (если есть в словаре) приоритетнее `activity_type`.

### `getSourceLabel(source)` — L118
Метка источника импорта из `SOURCE_LABELS`; фолбэк — сырое значение; null при пустом.

## `src/utils/workoutTypes.js` (53 строки)
Канонические словари типов тренировок: русские метки и CSS-переменные цветов.

### `WORKOUT_TYPE_LABEL` — L1 (const, экспорт)
Словарь тип → русская метка (easy, recovery, tempo, long, interval, fartlek, control, race, sbu, other/ofp/strength, cross, rest, free, walking, hiking, cycling, swimming, run/running).

### `WORKOUT_TYPE_COLOR` — L26 (const, экспорт)
Словарь тип → CSS var цвета (`var(--workout-*)`).

### `typeLabel(type)` — L47
Метка типа из `WORKOUT_TYPE_LABEL` (lowercase/trim); фолбэк «Тренировка».

### `typeColorVar(type)` — L51
CSS-переменная цвета типа; фолбэк `var(--text-tertiary)`.
