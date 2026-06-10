# Frontend components 1/6 (AppLayout, Calendar ч.1) — справочник

## `src/components/AppLayout.jsx` (151 строка)
Layout авторизованной зоны: хедер/нижняя навигация остаются смонтированными, при навигации меняется только контент (AppTabsContent). Управляет CSS-классами body и переменными вьюпорта для страницы чата (клавиатура, Telegram in-app).

### `AppLayout({ onLogout })` — L18
Пропсы: `onLogout`. Рендерит `TopHeader`, `UserDrawer`, `PlanGeneratingBanner`, `PageTransition` → `AppTabsContent`, `BottomNav`; хедер/навбар скрываются на `/chat` при открытой клавиатуре. Сторы/хуки: `useAuthStore` (api), `useWorkoutRefreshStore` (start/stopAutoRefresh при наличии api), `useMobileKeyboardState` (включён только на чате), `useLocation`.
Значимые эффекты: детект Telegram in-app (класс `body.tg-webview` по `window.TelegramWebviewProxy`/UA) — L31; запуск авто-обновления данных тренировок — L42; классы `chat-page-active`/`chat-keyboard-open` на body — L48/L54; CSS-переменные `--chat-runtime-top-offset`/`--chat-runtime-bottom-clearance` при открытой клавиатуре — L69; запись `--chat-vvh`/`--chat-vvtop` из `visualViewport` напрямую в rAF + гашение паразитного скролла окна — L98.

## `src/components/AppTabsContent.jsx` (200 строк)
Контент как вкладки: все посещённые экраны остаются смонтированными, при переключении меняется только видимость (без Suspense — собственный LazyTab через useEffect, чтобы избежать зависания Suspense-fallback при Zustand-обновлениях).

### `moduleCache` — L22
Module-level `Map` кеша загруженных lazy-модулей (key → Component).

### `useLazyModule(importFn, key)` — L24
Хук: загружает модуль через `importFn()` в useEffect (не React.lazy), кеширует в `moduleCache`, возвращает компонент или null.

### `LazyTab({ importFn, moduleKey, props })` — L48
Компонент-обёртка: пока модуль не загружен — `SkeletonScreen`, затем рендерит загруженный компонент с props.

### `importDashboard`…`importCoachPage` — L55–L66
12 стабильных фабрик динамического импорта экранов: DashboardScreen, CalendarScreen, StatsScreen, ChatScreen, TrainersScreen, SettingsScreen, AthletesOverviewScreen, CoachWorkspace, TemplatesScreen, AdminScreen, ApplyCoachForm, CoachPageEditor.

### `preloadSecondary()` — L73
Предзагрузка второстепенных экранов; вызывается через `requestIdleCallback` (timeout 3 c) либо `setTimeout` 1.5 c (L81). Основные (dashboard/calendar/stats) грузятся сразу на module-level (L69).

### `TAB_KEYS` — L87
Константа ключей вкладок (dashboard/calendar/stats/chat/trainers/settings/library/admin).

### `AppTabsContent({ onLogout })` — L98
Пропсы: `onLogout` (прокидывается в SettingsScreen). Определяет активную вкладку по pathname (`activeKey`, useMemo), держит Set смонтированных вкладок (`mountedTabs`). Гейты по роли: `/admin` только admin, `/library` только coach (Navigate на `/`); coach на `/` видит CoachWorkspace вместо Dashboard; `/trainers/apply` → ApplyCoachForm, `/trainers/page` → CoachPageEditor. Рендер панелей через внутренний `renderPane(tabKey, isActive, content)` (L142) — `app-tab-pane` + `AppErrorBoundary`. Сторы: `useAuthStore` (user.role), `useLocation`.

## `src/components/AppUpdateModal.jsx` (182 строки)
Модалка обновления Android-приложения: скачивает APK через Capacitor Filesystem с прогрессом и открывает установщик через FileOpener.

### `APK_FILE_NAME` — L5
Константа имени файла APK в кеш-директории (`planrun-update.apk`).

### `formatMb(bytes)` — L7
Форматирует байты в мегабайты с 1 знаком; null при невалидном входе.

### `AppUpdateModal({ updateInfo, onDismiss })` — L12
Пропсы: `updateInfo` ({version, download_url, force_update}), `onDismiss`. Состояние-машина `phase`: idle → downloading → installing | error; рендерит соответствующие экраны с прогресс-баром/спиннером; «Позже» скрывается при `force_update` и во время загрузки/установки. API: динамические импорты `@capacitor/filesystem` (downloadFile + listener 'progress') и `@capacitor-community/file-opener`. Значимые хендлеры: `startUpdate()` (L34, ~60 строк: удаление старого APK, подписка на прогресс, загрузка, открытие установщика, обработка ошибок), `retry()` (L98).

## `src/components/Calendar/AddTrainingModal.jsx` (1204 строки)
Bottom-sheet добавления/редактирования тренировки на дату (визуал v3 EditWorkoutSheet): единый ряд чипов типа, калькулятор простого бега «2 из 3», конструкторы интервалов/фартлека, библиотека упражнений ОФП/СБУ + кастомные упражнения. Также режим правки результата (`editResultData`).

### `CalcFieldV3({ label, unit, value, onChange, accent, placeholder, inputMode })` — L20
Презентационное поле калькулятора (лейбл + input + юнит) в стиле v3.

### `NumFieldV3({ value, onChange, unit, placeholder })` — L38
Компактное числовое поле с юнитом (разминка/заминка и т.п.).

### `TYPES_BY_CATEGORY` — L53
Списки типов тренировок по категориям running/ofp/sbu.

### `ALL_TYPE_CHIPS` — L68
Единый ряд чипов всех типов (value/label/cat) — шаг выбора категории убран.

### `TYPE_TO_CATEGORY` — L81
Маппинг типа тренировки → категория (running/ofp/sbu).

### `AddTrainingModal({ isOpen, onClose, date, api, onSuccess, initialData, editResultData, viewContext })` — L87
Пропсы: `initialData` (режим правки план-дня), `editResultData` (правка результата), `viewContext` (тренер смотрит атлета). Рендерит portal-шторку `atv3-sheet`: чипы типа, калькулятор/конструктор по типу, библиотеку ОФП/СБУ с переопределениями (дистанция СБУ; подходы×повторы×вес ОФП), кастомные упражнения, textarea описания, чекбокс «ключевая». Хуки/API: `useSheetFocus`; `api.request('list_exercise_library')`, `api.saveResult`, `api.request('get_csrf_token')`, `api.updateTrainingDay`, `api.addTrainingDayByDate`. Утилиты: `parseTime/formatTime/parsePace/formatPace/maskTimeInput/maskPaceInput`, `typeColorVar`.
Значимая внутренняя логика: `resetForm()` (L147); эффект инициализации из initialData/editResultData (L179); эффект восстановления упражнений ОФП/СБУ из `initialData.exercises` или парсинга description (L221, ~95 строк); эффект восстановления полей бег-калькулятора регэкспами из description (L321, ~70 строк); загрузка библиотеки упражнений (L396); `selectType(t)` (L416); `recalcSimpleRun(changed, newValue)` (L435) — при изменении одного поля пересчитывается ровно одно другое; генераторы описания `generateSimpleRunDescription` (L459) / `generateIntervalDescription` (L476) / `generateFartlekDescription` (L490); `intervalTotalKm` (L505) / `fartlekTotalKm` (L518) — useMemo суммарной дистанции; эффект синхронизации description с конструктором (L533); эффект описания из выбранных упражнений (L547); `addCustomExercise` (L605); `addFartlekSegment`/`removeFartlekSegment`/`updateFartlekSegment` (L638–L646); `handleSubmit` (L648, ~54 строки: saveResult ИЛИ CSRF + update/add training day); ESC + блокировка скролла body (L704).

## `src/components/Calendar/Calendar.jsx` (81 строка)
Старый (v2) компонент календаря: рендерит список недель плана через `Week`. НЕ используется приложением (CalendarScreen работает на v3) — мёртвая цепочка Calendar→Week→Day.

### `Calendar({ plan, progressData, workoutsData, resultsData, api, onDayPress, onRefresh, canEdit, isOwner })` — L10
Рендерит `Week` для каждой `plan.weeks_data` с подсветкой текущей недели; пустое состояние «План тренировок не найден». Хендлер `handleDeleteWeek` (L22) — `api.deleteWeek` + onRefresh.

### `getCurrentWeekNumber(plan)` — L56
Находит номер недели плана, в диапазон дат которой попадает сегодня; иначе null.

## `src/components/Calendar/Day.jsx` (99 строк)
Старая (v2) ячейка дня календаря. НЕ используется (импортируется только мёртвым `Week.jsx`).

### `Day({ dayData, dayKey, weekNumber, weekStartDate, progressData, workoutsData, resultsData, onPress })` — L11
Рендерит ячейку `training-cell`: дата/день недели, краткое описание (dangerouslySetInnerHTML от `getShortDescription`), сводка workout (км/время/темп) и результаты (result_time/distance/pace/notes). Утилиты: `getDateForDay`, `getTrainingClass`, `getShortDescription`, `formatDateShort`, `getDayName`.

## `src/components/Calendar/DayModal.jsx` (594 строки)
Модалка просмотра дня: план (WorkoutCard) + выполненные тренировки (стиль статистики) + заметки дня + действия (запланировать, отметить выполненной, копировать день, написать атлету). Используется в `UserProfileScreen` (просмотр чужого профиля/атлета).

### `stripHtml(s)` — L23
Снимает HTML-теги и схлопывает пробелы.

### `formatDurationDisplay(minutesOrSeconds, isSeconds)` — L25
Форматирует длительность в «Xч Yм» / «Yм Zс» / «Zс».

### `DayModal({ isOpen, onClose, date, weekNumber, dayKey, api, canEdit, viewContext, onOpenResultModal, onTrainingAdded, onEditTraining, refreshKey, openWorkoutDetailsInitially })` — L36
Рендерит portal-модалку `modal-modern`: шапка с датой и метриками, блок плана (`WorkoutCard`), список выполненных (`workout-item`, клик → `WorkoutDetailsModal`), сворачиваемые заметки дня, кнопки действий, копирование дня на дату, ссылка «Написать атлету» (navigate `/chat?contact=slug`). Вложенно рендерит `AddTrainingModal` и `WorkoutDetailsModal`. Сторы/хуки: `useAuthStore` (currentUser для прав на заметки), `useNavigate`. API: `api.getDay`, `api.getDayNotes`, `api.saveDayNote`, `api.deleteDayNote`, `api.deleteTrainingDay`, `api.copyDay`, `api.deleteWorkout`.
Значимые хендлеры: `loadNotes` (L63), `handleSaveNote` (L72), `handleUpdateNote` (L85), `handleDeleteNote` (L99), `loadDayData` (L109), `handleDeletePlanDay` (L136), `handleCopyDay` (L192), `handleDeleteWorkoutInline` (L206), `getWorkoutMetrics` (L225 — агрегация дистанции/времени/среднего темпа), `hasCompletedWorkouts` (L255 — IIFE-проверка осмысленных данных), автооткрытие деталей по `openWorkoutDetailsInitially` (L170).

## `src/components/Calendar/ResultModal.jsx` (912 строк)
Bottom-sheet ввода результата тренировки: мультиблоки простого бега, конструкторы интервалов/фартлека (предзаполняются регэксп-парсингом описания плана), запланированные ОФП/СБУ с полями «сделано», дополнительные упражнения. Сохраняет результат + структурированные executed_exercises (для AI progressive overload). Используется в CalendarScreen и StatsScreen.

### `TYPE_OPTIONS` — L20
Типы для кнопки «+ Добавить тип тренировки»: run/ofp/sbu с иконками.

### `createRunBlock(planDay, extraId)` — L27
Фабрика блока бега: id (plan-N / extra-N), type, planDayId, description, пустые distance/duration/pace/hr.

### `ResultModal({ isOpen, onClose, date, weekNumber, dayKey, api, onSave })` — L38
Рендерит portal-шторку `atv3-sheet`: блоки бега (`renderSimpleRunBlock` L621), форма интервалов (`renderIntervalForm` L641), фартлека (`renderFartlekForm` L670), запланированные ОФП/СБУ с инпутами «сделано» и кнопкой «не делал», свои упражнения (`renderCustomForm` L603 / `renderAdditionalList` L574), дропдаун добавления типа, заметки. Хуки: `useSheetFocus`. API: `api.getDay`, `api.getResult`, `api.saveResult`, `api.request('get_csrf_token')`, `api.request('mark_exercises_completed')`.
Значимая внутренняя логика: `updateRunBlock`/`recalcRunBlock` (L103/L111 — взаимный пересчёт дистанция↔время↔темп в блоке); `intervalTotalKm`/`fartlekTotalKm` (L124/L136); `resetAll` (L161); эффект предзаполнения интервалов/фартлека из описания плана (L180, ~40 строк регэкспов); `formatDuration(sec)` (L224); `expandDayExercises(exercises, category)` (L233, ~60 строк: разворачивает dayExercises в строки с planned*/done*-полями, prefill done значениями плана); `loadDayPlan` (L294 — getDay + предзаполнение блоков бега из описаний); `loadExistingResult(planDays)` (L328 — getResult, запоминает week_number/day_name существующей записи в `existingKeys` против дубликатов); `buildNotes` (L396 — сборка текстовых заметок из всех блоков); `getResultDistance`/`getResultTime`/`getResultPace` (L448/L456/L466); `handleSubmit` (L477, ~95 строк: определение activity_type_id 1/2/9, saveResult, затем mark_exercises_completed с CSRF, ошибки последнего не прерывают flow).

## `src/components/Calendar/RouteMap.jsx` (112 строк)
Примитивная карта маршрута через iframe OpenStreetMap embed. НЕ используется нигде (актуальные карты — Stats/MapboxRouteMap и Stats/LeafletRouteMap) — мёртвый код.

### `RouteMap({ workout, gpxData, coordinates })` — L11
Рендерит контейнер карты + статистику (набор высоты, темп); `loadMap()` (L22) императивно вставляет iframe openstreetmap.org/export/embed по bbox координат либо плейсхолдер для GPX. Возвращает null без coordinates/gpxData.

## `src/components/Calendar/v3/CalHeaderV3.jsx` (61 строка)
Шапка календаря v3: навигация периода стрелками + заголовок/подзаголовок + сегмент Неделя/Месяц + слот ⋯-меню.

### `CalHeaderV3({ title, subtitle, onPrev, onNext, viewMode, onViewMode, lockView, hideSeg, menu })` — L6
Презентационный компонент: стрелки ‹ › по краям, заголовок по центру; ряд сегмента (tablist week/full) скрывается при `lockView`/`hideSeg` (публичные профили); `menu` рендерится справа от сегмента.

## `src/components/Calendar/v3/calV3.js` (417 строк)
Общие константы и хелперы календаря v3 (порт redesign-прототипа): фазы мезоцикла, парсинг метрик из текста плана, построение сегментов бега, дата/неделя-хелперы, модели дня/недели/месяца, окно объёма.

### `typeLabel`, `typeColorVar` — L13 (re-export)
Реэкспорт из `utils/workoutTypes` (название и CSS-переменная цвета типа тренировки).

### `TYPE_LABEL` — L14
Алиас `WORKOUT_TYPE_LABEL` из utils/workoutTypes.

### `PHASES` — L17
Фазы мезоцикла: ключ → { label, color } (base/build/peak/taper/recovery).

### `PHASE_ORDER` — L25
Линейный порядок фаз для phase-полосы (без recovery).

### `derivePhaseKey(weeksDone, weeksTotal)` — L28
Эвристика фазы по доле пройденных недель: <25% base, <50% build, <80% peak, иначе taper.

### `phaseNameToKey(name)` — L39
Реальное название фазы (RU/EN подстроки) → ключ; forward-compatible с `week.phase` от бэка.

### `parseVolumeKm(totalVolume)` — L51
Строка total_volume («52.0 км») → число км.

### `parsePlanMetrics(text)` — L58
Извлекает `{ km, pace }` из текста описания дня регэкспами (темп «5:30/км», дистанция «N км», исключая дистанцию-часть-темпа).

### `stripHtml(s)` — L76
Снимает HTML и схлопывает пробелы.

### `RUN_SEGMENT_TYPES` — L80
Типы бега, для которых строится сегмент-бар (interval/fartlek/tempo/easy/recovery/long/long-run).

### `fmtSegKm(n)` — L82
Внутренний форматтер «X км» с 1 знаком.

### `buildRunSegments({ type, text, km, pace })` — L87
Строит `{ segs:[{type,w}], caption }` для визуального бара структуры: повторы по дистанции (N×M м/км) или по времени (N×M мин/сек) с разминкой/заминкой/восстановлением из текста, иначе равномерный/темповый сплит от totalKm. ~70 строк регэксп-эвристик.

### `ymd(date)` — L162, `addDays(dateStr, delta)` — L168, `todayYmd()` — L173, `getMondayForDate(dateStr)` — L178, `getMondayOfToday()` — L184
Дата-хелперы: формат YYYY-MM-DD, сдвиг на N дней, сегодня, понедельник недели даты/сегодня.

### `getVirtualWeek(startDateStr)` — L191
Пустая (виртуальная) неделя {number:0, days:null×7} для дат вне плана. Экспортирован, внешних потребителей нет (используется внутри getWeekForStartDate).

### `getWeekForStartDate(plan, startDateStr)` — L196
Неделя плана по понедельнику (точное совпадение start_date, затем попадание в диапазон), иначе виртуальная.

### `typicalPlanPaceByType(plan)` — L230
Внутренний: самый частый темп по типу тренировки во всём плане (WeakMap-кеш `_typicalPaceCache` L229 по объекту плана).

### `paceToMin(pace)` — L255
«M:SS» → минуты числом (десятичные).

### `suggestPaceByType(plan, type, km)` — L262
Фолбэк-темп для дня без темпа: типичный темп этого типа в плане; null для NO_PACE_TYPES (L253).

### `estimateTimeMin(km, pace)` — L267
Оценка времени: км × темп, иначе км × 5.4 мин.

### `buildDayModel(dateStr, plan, data, weekNumber)` — L273
Главный билдер модели дня v3: `{ date, dow, dayKey, type, typeLabel, title, km, pace, paceSuggested, status (done/rest_extra/plan/rest), key, activities, items, isToday, restExtraType }`. Использует `getPlanDayForDate` и `getDayCompletionStatus` из calendarHelpers, parsePlanMetrics, suggestPaceByType.

### `buildWeekDays(plan, week, data)` — L330
7 моделей дня недели от week.start_date.

### `buildMonthMatrix(plan, year, month, data)` — L341
Ячейки месяца: ведущие null (выравнивание ПН-первый) + модели дней.

### `formatMonthTitle(year, month)` — L354
«Месяц ГГГГ» по-русски (MONTH_NAMES L353).

### `buildVolumeWindow(plan, currentStartDate)` — L359
4-недельное окно объёма вокруг текущей недели для VolumeRail: `{ items:[{n, vol, current, phase, range, startDate}], max }`; фаза из week.phase либо эвристики.

### `deriveCurrentPhase(plan, currentStartDate)` — L392
Ключ фазы текущей недели (по start_date/диапазону, week.phase либо derivePhaseKey по индексу).

### `formatWeekRange(startDateStr)` — L411
Диапазон недели «11–17 мая» (родительный падеж месяцев, MONTHS_GEN L410).

## `src/components/Calendar/v3/CopyWeekV3.jsx` (76 строк)
Копирование недели на другую дату (для тренера); два варианта рендера: отдельная кнопка (десктоп) и пункт ⋯-меню.

### `CopyWeekV3({ api, weekId, viewContext, onCopied, variant })` — L6
Раскрывает date-инпут; `doCopy` (L13) нормализует выбранную дату к понедельнику и вызывает `api.copyWeek(weekId, monday, viewContext)`. Возвращает null без weekId/api.copyWeek. Используется в WeekViewV3.

## `src/components/Calendar/v3/dayCache.js` (63 строки)
Version-aware кеш данных дня (getDay) для DaySheet: повторное открытие — мгновенно; при росте `useWorkoutRefreshStore.version` кеш сбрасывается.

### `cacheVersion` — L7, `cache` — L8
Module-level состояние: текущая версия и Map ключ→данные дня.

### `dayCacheKey(date, viewContext)` — L10
Ключ кеша `date|viewContext`.

### `syncDayCacheVersion(version)` — L13
Сравнивает с глобальной версией; при отличии очищает кеш.

### `getCachedDay(key)` — L20, `setCachedDay(key, data)` — L21
Чтение/запись кеша.

### `normalizeDay(raw)` — L23
Внутренний: нормализация ответа getDay в { planDays, dayExercises, workouts } (camelCase/snake_case).

### `prefetchDays(api, dates, viewContext, version)` — L33
Фоновый префетч недостающих дней: одним batch-запросом `api.getDays`, фолбэк — параллельные `api.getDay` по одному. Используется в WeekViewV3.

## `src/components/Calendar/v3/DayCompletedV3.jsx` (318 строк)
Инлайн-детали выполненной тренировки в DaySheet (и в Stats/WorkoutSheet): сворачиваемая карточка с картой, вкладками Обзор/Данные/Круги/Графики, шарингом и удалением. Грузит timeline/laps.

### `RouteMap` — L14
Lazy-компонент карты: MapboxRouteMap при `VITE_MAPBOX_TOKEN`, иначе LeafletRouteMap (из Stats).

### `DayCompletedV3({ workout, date, api, defaultType, canEdit, onEdit, onDelete, relation, defaultExpanded, hideToggle, chartsInline })` — L18
Рендерит шапку (тип/бейдж «ПО ПЛАНУ»/«ВНЕ ПЛАНА», свёрнутое summary км·темп·пульс, WorkoutShareButton, шеврон), тело с картой (Suspense+MapLoadingSkeleton), вкладками, метрик-картами, таблицей кругов, `CombinedWorkoutChart` (hoverIndex синхронизирует карту), кнопками Редактировать (только manual)/Удалить + confirm-диалог через portal. API: `api.getWorkoutTimeline` (только не-manual, лениво по mounted). Утилиты: `formatWorkoutDuration/formatLapDuration/formatLapPace`, `getSourceLabel`, `typeColorVar/typeLabel`.
Значимая логика: `toggle` (L29) — отложенный размонтаж тяжёлого контента (300 мс) на время анимации сворачивания; эффект загрузки timeline/laps (L39).

## `src/components/Calendar/v3/DaySheetV3.jsx` (376 строк)
Поверхность дня v3 (bottom-sheet на мобиле / embedded-карточка на десктопе): план (метрики, сегмент-бар, структура разминка→бег→заминка, упражнения ОФП/СБУ, AI-заметка), факт-сравнение с планом (▲/▼) и инлайн-детали выполненных тренировок.

### `hasMeaningfulWorkout(w)` — L14
Тренировка с реальными данными (дистанция или длительность > 0).

### `parseRunStructure(day)` — L21
Разминка/заминка простого бега из текста описания (км + темп) регэкспами; null если нет.

### `buildSegments(day)` — L35
Сегмент-бар для бегового item дня через `buildRunSegments`.

### `DaySheetV3({ day, embedded, canEdit, api, viewContext, onClose, onEdit, onMarkDone, onEditResult, onDeleteResult, onAddTraining })` — L41
Пропсы: `day` — модель из buildDayModel; колбэки действий. Рендерит шапку (тип/КЛЮЧ/ВЫПОЛНЕНО, ✎), метрики план vs факт (км/темп/время с дельтами `deltaSpan` L145), сегмент-бар или структурный список, `ExerciseListV3`, AI-заметку (текст плана без продублированных разминки/заминки), CTA «Отметить выполненной», правую колонку с `DayCompletedV3` по каждой выполненной (matched «по плану» первой), кнопку «+ Добавить тренировку» (mobile). Сторы/хуки: `useWorkoutRefreshStore` (version → инвалидация кеша), `useSheetFocus`, кеш dayCache (`syncDayCacheVersion/getCachedDay/setCachedDay`). API: `api.getDay`. Утилиты: `planTypeToCategory/workoutTypeToCategory` (матчинг факта к плану по категории — бег↔бег), `formatWorkoutDuration`, `estimateTimeMin/paceToMin`.
Значимая логика: эффект загрузки дня с кешем (L61); `exItems` useMemo нормализации dayExercises (L92); `editablePlanDay` useMemo — полный план-день для редактора с фолбэком на сырой item (L103).

## `src/components/Calendar/v3/ExerciseListV3.jsx` (28 строк)
Список упражнений ОФП/СБУ (подходы×повторы), общий для DaySheetV3 и Stats/WorkoutSheet.

### `setsLabel(ex)` — L3
Лейбл объёма упражнения: «3×12», «3×30 с», длительность/дистанция/вес — по приоритету. Экспортирован, внешних потребителей нет.

### `ExerciseListV3({ items })` — L12
Нумерованный список: имя, подсказка-вес, лейбл подходов. Null при пустом списке.

## `src/components/Calendar/v3/InfoTip.jsx` (35 строк)
«i» в кружочке с тултипом: клик-тоггл (тач и десктоп), закрытие по клику вне/ESC, hover через CSS.

### `InfoTip({ text, children, label })` — L5
Кнопка `calv3-infotip__btn` + поповер `calv3-infotip__pop`; эффект подписки на pointerdown/keydown при open.

## `src/components/Calendar/v3/MonthViewV3.jsx` (204 строки)
Месячный вид календаря v3. Мобайл: мини volume-rail + сетка точек + легенда + bottom-sheet дня. Десктоп: сетка карточек + правый rail (объём, фазы, embedded DaySheet).

### `MonthCellMobile({ day, onClick })` — L21
Ячейка мобильной сетки: число + ✓ (выполнено) или цветная точка типа.

### `MonthCellDesktop({ day, active, onClick })` — L38
Ячейка десктопной сетки: число, ✓, цветная полоска-акцент, лейбл типа, км, либо «Отдых».

### `MonthViewV3({ plan, data, canEdit, viewMode, onViewMode, lockView, hideSeg, initialDate, api, viewContext, onEditDay, onMarkDone, onEditResult, onDeleteResult, onAddTraining, planMenu })` — L63
Держит `cursor` {year, month}, `sheetDayDate` (мобайл-шторка; модель дня выводится из свежих cells, чтобы не залипала после loadPlan), `selectedDate` (десктоп). useMemo: `buildMonthMatrix` (cells), `buildVolumeWindow`. Рендерит `CalHeaderV3` (мобайл) или собственный desk-ряд с «Сегодня»/«+ Тренировка»/planMenu, сетку из MonthCell*, `VolumeRailV3`, `PhasesListV3`, `DaySheetV3` (шторка / embedded). Хуки: `useIsMobile`. Хендлеры `goPrev/goNext/goToday` (L92–94); `sheetCallbacks` (L96) прокидывает действия и закрывает шторку перед onAddTraining. Единственный потребитель — CalendarScreen.

## `src/components/Calendar/v3/PhaseRibbonV3.jsx` (26 строк)
Полоса фазы мезоцикла: 4 сегмента PHASE_ORDER, активный закрашен цветом фазы + название.

### `PhaseRibbonV3({ phase })` — L5
Презентационный; null для неизвестной фазы. Используется в WeekViewV3.

## `src/components/Calendar/v3/PhasesListV3.jsx` (55 строк)
Карточка «Фазы мезоцикла»: список недель окна объёма с фазой/диапазоном/км + ⓘ-пояснение. Общая для недельного и месячного видов (десктоп rail).

### `PHASE_HELP` — L8
Пары [ключ фазы, краткое описание] для тултипа.

### `PhasesHelp()` — L16
Внутренний контент тултипа: список фаз с цветными точками и описаниями.

### `PhasesListV3({ items })` — L30
Пропсы: `items` из buildVolumeWindow ({n, phase, range, vol, current}). Рендерит ряды недель с цветным баром фазы и тегом «СЕЙЧАС»; ⓘ через `InfoTip`.
