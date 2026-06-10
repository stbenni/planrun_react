# Frontend components 3/6 (common ч.2, Dashboard ч.1) — справочник

## `src/components/common/Icons.jsx` (386 строк)
Единый пул иконок проекта: тонкие обёртки над lucide-react (size 20, strokeWidth 1.8, aria-hidden) + кастомные SVG (RunningIcon, SendIcon). В конце — маппинг типа активности на иконку.

Все иконки-компоненты (каждая принимает `props`, прокидываемые в lucide-иконку):

- `RunningIcon({className, size, ...props})` — L88 — бег, кастомный fill-SVG (бегущий человек, viewBox 32×32)
- `WalkingIcon` — L113 — ходьба (Footprints)
- `HikingIcon` — L116 — поход (Mountain)
- `CyclingIcon` — L119 — велосипед (Bike)
- `SwimmingIcon` — L122 — плавание (Waves)
- `OtherIcon` — L125 — ОФП/прочее (Dumbbell)
- `SbuIcon` — L128 — СБУ (Zap)
- `FootprintsIcon` — L131 — следы (Footprints); дубль WalkingIcon
- `MoonIcon` — L134 — луна (Moon)
- `RestIcon` — L137 — отдых (Moon); дубль MoonIcon
- `CompletedIcon` — L140 — выполнено (Check)
- `DistanceIcon` — L146 — дистанция (Route)
- `TimeIcon` — L149 — время (Clock)
- `PaceIcon` — L152 — темп (Gauge)
- `BotIcon` — L158 — AI-бот (Bot)
- `MailIcon` — L161 — почта (Mail)
- `CalendarIcon` — L164 — календарь (Calendar)
- `CheckIcon` — L167 — галочка (Check)
- `AlertTriangleIcon` — L170 — предупреждение (AlertTriangle)
- `HeartIcon` — L173 — пульс/сердце (Heart)
- `ZapIcon` — L176 — молния (Zap)
- `TrendingUpIcon` — L179 — тренд вверх (TrendingUp)
- `TrendingDownIcon` — L182 — тренд вниз (TrendingDown)
- `TargetIcon` — L185 — цель (Target)
- `GraduationCapIcon` — L188 — обучение (GraduationCap)
- `UserIcon` — L194 — пользователь (User)
- `LockIcon` — L197 — замок (Lock)
- `LinkIcon` — L200 — ссылка/интеграции (Link2)
- `LogOutIcon` — L203 — выход (LogOut)
- `UploadIcon` — L206 — загрузка (Upload)
- `TrophyIcon` — L209 — кубок (Trophy)
- `FlameIcon` — L212 — огонь/стрик (Flame)
- `BarChartIcon` — L215 — график (BarChart3)
- `FingerprintIcon` — L218 — отпечаток/биометрия (Fingerprint)
- `MessageCircleIcon` — L221 — сообщение (MessageCircle)
- `BellIcon` — L224 — колокольчик (Bell)
- `SendIcon({size, ...props})` — L228 — отправить, кастомный fill-SVG (бумажный самолётик)
- `SmileIcon` — L243 — смайл/эмодзи (Smile)
- `PaperclipIcon` — L247 — скрепка/вложение (Paperclip)
- `MicIcon` — L251 — микрофон (Mic)
- `PlayIcon` — L255 — play (Play)
- `PauseIcon` — L259 — пауза (Pause)
- `SmartphoneIcon` — L262 — смартфон (Smartphone)
- `ImageIcon` — L265 — изображение (Image)
- `CameraIcon` — L268 — камера (Camera)
- `PaletteIcon` — L271 — палитра/тема (Palette)
- `UsersIcon` — L274 — группа (Users)
- `ClipboardListIcon` — L277 — список/план (ClipboardList)
- `MapPinIcon` — L280 — геометка (MapPin)
- `GlobeIcon` — L283 — глобус (Globe)
- `MountainIcon` — L286 — гора (Mountain)
- `TrashIcon` — L289 — удалить (Trash2)
- `ShareIcon` — L292 — поделиться (Share2)
- `LeafIcon` — L295 — лист/восстановление (Leaf)
- `PenLineIcon` — L298 — редактировать (PenLine)
- `PointerIcon` — L301 — указатель (Pointer)
- `MedalIcon` — L304 — медаль (Medal)
- `FlagIcon` — L307 — флаг/финиш (Flag)
- `CloseIcon({size=18, strokeWidth=2.4})` — L310 — закрыть (X), свои дефолты размера
- `XCircleIcon` — L313 — крестик в круге (XCircle)
- `SettingsIcon` — L316 — настройки (Settings)
- `SkipForwardIcon` — L319 — пропустить (SkipForward)
- `InfoIcon` — L322 — инфо (Info)
- `ArrowLeftRightIcon` — L325 — обмен/swap (ArrowLeftRight)
- `ChevronUpIcon` — L328 — шеврон вверх (ChevronUp)
- `ChevronDownIcon` — L331 — шеврон вниз (ChevronDown)
- `PlusIcon` — L334 — плюс (Plus)
- `RepeatIcon` — L337 — повтор (Repeat)
- `FeatherIcon` — L340 — перо/лёгкость (Feather)
- `RouteIcon` — L343 — маршрут (Route)
- `ShuffleIcon` — L346 — перемешать (Shuffle)
- `PersonStandingIcon` — L349 — человек (PersonStanding)
- `TimerIcon` — L352 — таймер (Timer)
- `ActivityIcon` — L355 — активность (Activity)
- `DumbbellIcon` — L358 — гантеля (Dumbbell)
- `HelpCircleIcon` — L361 — помощь (HelpCircle)

### `ACTIVITY_ICONS` — L367
Внутренний const-маппинг типа тренировки → иконка (running/walking/hiking/cycling/swimming/other/easy/long/tempo/interval/sbu/fartlek/rest).

### `ActivityTypeIcon({type, className, ...props})` — L383
Выбирает иконку из ACTIVITY_ICONS по `type` (fallback RunningIcon) и рендерит её.

## `src/components/common/InfoTooltip.jsx` (130 строк)
Иконка «i» с popover-подсказкой по клику/наведению. Popover рендерится порталом в document.body (position: fixed) с позиционированием относительно триггера и клампом к границам viewport.

### `InfoTooltip({title, content, size=14})` — L15
Кнопка-триггер с InfoIcon + portal-popover (title + content, стрелка указывает на центр иконки). Закрытие: клик вне, Escape, mouseleave. Хуки: useState/useRef/useCallback/useLayoutEffect; слушатели scroll/resize пересчитывают позицию. Значимый внутренний хендлер: `updatePosition` (L29) — расчёт top/left/arrowLeft и placement top/bottom с учётом viewport.

## `src/components/common/LockScreen.jsx` (182 строки)
Экран блокировки приложения при запуске: вход по PIN (экранная клавиатура) и/или биометрии; только для нативного Capacitor.

### `LockScreen()` — L15
Без пропсов. Рендерит лого, PinInput с keypad (extra-кнопка биометрии), кнопку «Войти по отпечатку» (если PIN выключен), ошибки (включая network-режим с кнопкой «Повторить»), ссылку «Войти по паролю». Сторы/сервисы: useAuthStore (`pinLogin`, `biometricLogin`, `beginPasswordReauth`, `checkBiometricAvailability`, `checkPinAvailability`, `tryAutoTriggerBiometric`), `isNativeCapacitor` из TokenStorageService, useNavigate. Авто-вызов биометрии при открытии — guard в store. Значимые хендлеры: `handlePinSubmit` (L45), `handleBiometricLogin` (L66), `handleLoginByPassword` (L87, редирект на /landing?openLogin=1 в нативе).

## `src/components/common/LogoLoading.jsx` (16 строк)
Индикатор загрузки: логотип planRUN с shimmer-анимацией.

### `LogoLoading({className, size='default'})` — L7
Рендерит span-лого с CSS-классом размера `logo-loading--${size}`.

## `src/components/common/Modal.jsx` (95 строк)
Базовое модальное окно: портал в `#modal-root` (fallback body), блокировка скролла body, закрытие по Esc/клику на backdrop.

### `Modal({isOpen, onClose, title, children, size='medium', hideHeader, centerBody, variant='default', headerActions, headerSubtitle, contentClassName, bodyClassName, mobilePresentation='default', disableBackdropClose})` — L11
Рендерит backdrop + контент с хедером (title/subtitle/actions/крестик) и body. Варианты: `modern`, мобильный fullscreen (`mobilePresentation='fullscreen'`). `disableBackdropClose` отключает Esc и клик по фону.

## `src/components/common/NotificationBell.jsx` (114 строк)
Колокольчик уведомлений с живым бейджем; агрегатор useNotificationFeed живёт здесь, чтобы счётчик был актуален при закрытой панели. Панель — поповер (десктоп) / bottom-sheet (мобайл) через портал.

### `NotificationBell({api})` — L17
Кнопка с BellIcon и бейджем unread (99+ cap); по клику открывает NotificationCenter в портале (#modal-root/body), якорь под кнопкой. Хуки: `useNotificationFeed(api)` (items, counts, mark/dismiss), useNavigate. Закрытие: Esc, pointerdown вне панели. Хендлеры: `handleNavigate` (закрыть + navigate), `handleOpenSettings` (→ /settings?tab=notifications).

## `src/components/common/notificationCategories.js` (80 строк)
Справочник категорий уведомлений: маппинг backend-типа из plan_notifications в категорию для табов, иконок, дефолтных заголовков и action-лейблов.

### `NOTIF_CATEGORY` — L7
Экспорт-const: 7 категорий (ai/coach/workout/achievement/race/plan/system) с key+label.

### `NOTIF_CATEGORY_ORDER` — L18
Экспорт-const: порядок табов в панели.

### `categoryForType(type, source)` — L25
Категория по типу уведомления и источнику ('ai'/'coach' источники имеют приоритет; затем switch по типу: chat.*, coach_plan_updated, personal_record, workout_uploaded, race_countdown, plan_ready и т.д.; default 'system').

### `defaultTitleForCategory(category)` — L64
Дефолтный заголовок категории (внутренний DEFAULT_TITLE L54).

### `defaultActionForCategory(category)` — L78
Дефолтный action-лейбл («Открыть чат →» и т.п.; внутренний DEFAULT_ACTION L68).

## `src/components/common/NotificationCenter.jsx` (227 строк)
Презентационная панель уведомлений: хедер со счётчиками, табы-фильтры, группировка Сегодня/Вчера/Ранее, карточки со свайп-дисмиссом, футер с настройками. Данные приходят пропсами из useNotificationFeed.

### `pluralRu(n, one, few, many)` — L25
Русская плюрализация по правилам числительных.

### `formatTimeAgo(date)` — L33
Относительное время: «сейчас», «N мин», «N ч», «вчера», «N дней», далее — дата ru-RU.

### `dayBucket(date)` — L46
Бакет дня: 'today' | 'yesterday' | 'earlier'.

### `NotifCard({item, onOpen, onDismiss})` — L58
Карточка уведомления: категорийный аватар (CATEGORY_VISUAL L15), title/body/время/action, точка unread, кнопка-крестик. Свайп влево (touch) на ≥80px (SWIPE_DISMISS_PX) запускает дисмисс с анимацией 200мс; клик (если не было drag) → onOpen.

### `NotificationCenter({items, counts, markRead, markAllRead, dismiss, dismissAll, onNavigate, onOpenSettings})` — L128
Хедер (unread + «Прочитать все»), табы (Все/Новые + присутствующие категории, useMemo), фильтрация и группировка по бакетам, пустое состояние, футер («Настройки уведомлений», «Очистить всё»). Хендлер `handleOpen` — markRead + переход по item.link.

## `src/components/common/PageTransition.jsx` (14 строк)
Обёртка для плавных переходов между страницами; без key — не перемонтируется при навигации, анимация задаётся CSS.

### `PageTransition({children})` — L10
Рендерит `div.page-transition` с children.

## `src/components/common/PinInput.jsx` (133 строки)
Поле ввода PIN из 4 цифр: скрытый input + визуальные точки; опционально экранная цифровая клавиатура (без вызова нативной).

### `PinInput({length=4, value, onChange, onComplete, disabled, error, placeholder, autoFocus=true, showKeypad=false, keypadExtra})` — L17
Контролируемый/локальный ввод (только цифры, обрезка до 4; внутренняя `len` жёстко = 4, проп `length` фактически игнорируется). При заполнении вызывает onComplete. С showKeypad: readOnly input + сетка KEYPAD_LAYOUT (L10) с backspace и слотом keypadExtra (например, кнопка биометрии). Хендлеры: handleChange, handleKeypadDigit, handleKeypadBackspace.

## `src/components/common/PinSetupModal.jsx` (132 строки)
Модалка установки PIN: два шага — ввод и подтверждение; сохраняет PIN + токены через PinAuthService.

### `PinSetupModal({isOpen, onClose, onSuccess, tokens})` — L12
Шаг 1: PinInput + «Далее»; шаг 2: повторный ввод с onComplete. Значимый хендлер `handlePin2Complete` (L47): сверка PIN, проверка tokens, вызов `PinAuthService.setPinAndSaveTokens(pin, accessToken, refreshToken)` с таймаутом 15с через Promise.race, затем onSuccess + закрытие. Рендерит через общий Modal (variant="modern").

## `src/components/common/PlanGeneratingBanner.jsx` (41 строка)
Глобальный баннер «план генерируется», виден на всех страницах кроме Calendar и Dashboard (у них свои баннеры).

### `PlanGeneratingBanner()` — L13
Подписан на usePlanStore (`isGenerating`, `generationLabel`, `initPlanStatus`) и useAuthStore (`api`); при маунте вызывает initPlanStatus (восстановление статуса после F5). Скрыт на /calendar и /(dashboard).

## `src/components/common/PublicHeader.jsx` (55 строк)
Хедер публичных страниц (профили): логотип + кнопки «Вход» и «Регистрация».

### `PublicHeader({onLoginClick, onRegisterClick, registrationEnabled=true})` — L10
Колбэки или fallback-навигация на /login и /register; клик по лого → /landing.

## `src/components/common/SettingsPanel.jsx` (58 строк)
Выезжающая панель настроек: slide-in справа (десктоп) / фуллскрин (мобайл); внутри lazy SettingsScreen в режиме inPanel.

### `SettingsPanel()` — L17
Управляется флагом `settingsPanelOpen`/`setSettingsPanelOpen` из useAuthStore. Закрывается при смене location.pathname и по Esc; блокирует скролл body. Портал в document.body, Suspense-фоллбэк «Загрузка…». Внутренний const `SettingsScreen` (L15) — lazy-импорт экрана настроек.

## `src/components/common/SkeletonScreen.jsx` (237 строк)
Скелетоны загрузки экранов: набор статичных placeholder-вёрсток по типу экрана.

### `SkeletonScreen({type='default'})` — L4
Ветвится по type: 'dashboard' (грид метрик + карточки тренировок), 'calendar' (week strip + карточки дней), 'stats' (табы + 4 метрики + bar-чарт + список), 'chat' (список диалогов + сообщения + инпут), 'settings' (табы + аватар + форма), default — три строки. Без логики, только разметка.

## `src/components/common/TopHeader.jsx` (254 строки)
Верхняя навигация для десктопа (≥1024px): лого, табы с анимированной pill-подсветкой, бейджи тренера, колокольчик, чат, аватар. На мобиле возвращает null (навигация через BottomNav + UserDrawer).

### `initials(user)` — L22
Обёртка над getInitials из utils/displayName.

### `isNarrowViewport()` — L25
true при window.innerWidth < 1024 (мобильный режим).

### `TopHeader()` — L27
Без пропсов. Сторы: useAuthStore (`user`, `api`, getState().updateUser — дозагрузка avatar_path через `api.getCurrentUser()`), useCoachStore (`athletes`, `events` → бейджи «Команда» и «Поток»: risk+question). Наборы табов: navItemsUser (Дэшборд/Календарь/Статистика/Тренеры) и navItemsCoach (Команда/Поток/Календарь/Чат/Аналитика/Шаблоны), + «Админка» для admin. Рендерит NotificationBell, ChatNotificationButton, кнопку «Настроить план» (если онбординг не завершён), аватар → публичный профиль. Значимые внутренние: `isActive(item)` (L96) — активность таба с различением coach-вью по ?view=; `updateNavPill` (L112) — позиция/ширина pill по активному табу; layout-эффект с ResizeObserver + document.fonts.ready (L137) для пересчёта pill.

## `src/components/common/useNotificationFeed.js` (129 строк)
Хук-агрегатор фида уведомлений из единого store (plan_notifications), включая чат-события; нормализация, счётчики по категориям, mark read/dismiss.

### `toDate(v)` — L18
Безопасный парсинг даты (число или строка с заменой ' '→'T'), fallback epoch 0.

### `normalizeRow(n)` — L24
Нормализует запись plan_notifications в item фида: id `plan_<id>`, категория из metadata/categoryForType, title/body/link/actionLabel с дефолтами категории, read по read_at.

### `useNotificationFeed(api)` — L44
Загружает параллельно `api.getPlanNotifications({includeRead, limit:50})` + `api.getNotificationsDismissed()`, фильтрует dismissed, сортирует по времени. Поллинг каждые 60с (REFRESH_MS L16) + подписка на ChatSSE для мгновенного обновления; при 429 — cooldown по retry_after. Возвращает {items, loading, counts{total,unread,byCategory}, markRead, markAllRead, dismiss, dismissAll, refresh}. `markRead` дополнительно гасит непрочитанное диалога (`api.chatMarkRead`), `markAllRead` → `api.markAllPlanNotificationsRead`, dismiss'ы → `api.dismissNotification` (оптимистично).

## `src/components/common/UserDrawer.jsx` (183 строки)
Боковой drawer профиля/настроек; открывается из TopHeader (десктоп) и кнопки «Профиль» в BottomNav (мобайл).

### `UserDrawer()` — L24
Стор: useAuthStore (`user`, `logout`, `drawerOpen`, `setDrawerOpen`). Пункты: Профиль/Настройки тренировок/Уведомления/Конфиденциальность/Интеграции (→ /settings?tab=...), «Найти тренера» (не для coach), «Админка» (admin), «Выйти». Закрытие по Esc, клику на backdrop, смене маршрута; блокирует скролл body. Значимый хендлер `handleLogout` (L62): logout + редирект на /landing (window.location в нативе через isNativeCapacitor).

## `src/components/common/VoiceMessage.jsx` (66 строк)
Плеер голосового сообщения: play/pause, прогресс-бар с seek по клику, отображение времени.

### `fmtTime(s)` — L10
Секунды → «M:SS».

### `VoiceMessage({src, duration=0})` — L17
Скрытый `<audio preload="metadata">` + кнопка play/pause + трек прогресса. Колбэки: `toggle` (play/pause), `onTime` (обновление прогресса; duration из metadata или пропса), `seek` (клик по треку → currentTime).

## `src/components/Dashboard/AthleteMobileTabs.jsx` (74 строки)
Sticky-навигация секций Dashboard на мобиле: 4 таба (Сегодня/Неделя/Цель/Прогресс), скролл к секции, подсветка активной через IntersectionObserver. Используется только старым Dashboard.jsx.

### `AthleteMobileTabs()` — L21
IntersectionObserver (rootMargin '-30% 0px -55% 0px') по элементам `#dashboard-section-<id>` из TABS (L14) подсвечивает активный таб; `handleClick` (L51) — smooth-скролл к секции с поправкой на высоту sticky-tabs (−64px).

## `src/components/Dashboard/DailyBriefingHero.jsx` (81 строка)
Hero-карточка утреннего AI-брифинга (event_key=coach.proactive_daily_briefing из cron); не рендерится, если свежего (≤36ч) брифинга нет. Потребителей в src сейчас нет.

### `formatBriefingDate(iso)` — L15
«Сегодня»/«Вчера» или «<день недели>, D <месяц>».

### `DailyBriefingHero({api, onOpenChat})` — L28
Загружает `api.getLatestProactiveMessage('daily_briefing', 36)`; рендерит кнопку-карточку с BotIcon, датой, текстом брифинга и CTA «Открыть чат» (onOpenChat).

## `src/components/Dashboard/dashboardConfig.js` (16 строк)
Конфиг модулей дашборда (старого): id, подписи, ключ localStorage, набор «спариваемых» модулей.

### `DASHBOARD_MODULE_IDS` — L1
Экспорт-const: 9 id модулей (goal_countdown, today_workout, next_workout, trend_compare, personal_records, race_prediction, training_load, calendar, stats).

### `DASHBOARD_MODULE_LABELS` — L3
Экспорт-const: русские подписи модулей.

### `STORAGE_KEY` — L15
Экспорт-const: 'planrun_dashboard_modules'.

### `PAIRABLE_MODULE_IDS` — L16
Экспорт-const Set: модули, которые можно ставить по два в строку.

## `src/components/Dashboard/dashboardDateUtils.js` (54 строки)
Утилиты дат и нормализации дней плана для дашборда (используются useDashboardData и старым Dashboard).

### `getDayItems(dayData)` — L1
Нормализует день плана (объект или массив) в массив активностей без rest/free.

### `toLocalDateString(date)` — L7
Date → 'YYYY-MM-DD' в локальной таймзоне.

### `getTodayInTimezone(ianaTimezone)` — L14
Сегодняшняя дата 'YYYY-MM-DD' в заданной IANA-таймзоне через Intl.DateTimeFormat('en-CA'); fallback — локальная.

### `addDaysToDateStr(dateStr, days)` — L32
'YYYY-MM-DD' + N дней (через Date.UTC, без сдвига таймзоны).

### `dayItemsToWorkoutAndPlanDays(items, date, weekNumber, dayKey)` — L38
Из активностей дня собирает {workout (первая активность + date/weekNumber/dayKey), planDays (все: id/type/description)} или null.

## `src/components/Dashboard/Dashboard.jsx` (799 строк)
Старый главный экран дашборда атлета с настраиваемыми блоками (dnd-kit-кастомайзер, парные строки на десктопе) и pull-to-refresh. НЕ ИСПОЛЬЗУЕТСЯ: DashboardScreen рендерит `v3/DashboardV3`; никем не импортируется (живёт только Dashboard.css).

### `CustomizerStripZone({rowIndex, children})` — L46
Droppable-полоска «вставить перед строкой N» (useDroppable, id `insert-<row>`).

### `CustomizerItemPreview({moduleId})` — L60
Карточка для DragOverlay — статичная копия элемента списка.

### `CustomizerMergeZone({active})` — L70
Оформление зоны «+ в одну строку» (сама droppable-зона — вся строка).

### `CustomizerDraggableItem({rowIndex, slotIndex, moduleId, onRemove, mergeActive})` — L79
Перетаскиваемый элемент кастомайзера (useDraggable, id `slot-<row>-<slot>`); кнопка «Убрать» гасит pointer-события drag.

### `CustomizerRow({row, rowIndex, layout, setLayout, saveLayout, isMobileView})` — L106
Строка кастомайзера: слоты + droppable-merge (id `merge-<row>`, активна для строк из одного блока на десктопе).

### `isAiPlanMode(trainingMode)` — L139
true для training_mode === 'ai'.

### `MODULE_TO_SECTION` — L144
Const-маппинг модуль → секция sticky-tabs v3 (today/week/goal/form/pr/more).

### `Dashboard({api, user, isTabActive=true, onNavigate, registrationMessage, isNewRegistration})` — L156
Главный компонент (default export). Состояния: layout (getStoredLayout + нормализация для мобилы expandLayoutForMobile), кастомайзер (DndContext, сенсоры Pointer/Touch/Keyboard, коллизии pointerWithin→rectIntersection), expandedWorkoutCard ('today'|'next'). Сторы/хуки: useAuthStore (setPlanGenerationMessage), usePlanStore (generationLabel), `useDashboardData` (todayWorkout/nextWorkout/plan/progressDataMap/planGenerating/planError и пр.), `useDashboardPullToRefresh`. Ранние возвраты: онбординг-заглушка, SkeletonScreen, AI empty-state «Создать план». Рендер секций по displayLayout: today_workout (TodayHeroV3), next_workout (WorkoutCard), trend_compare (TrendComparisonWidget), personal_records, goal_countdown, calendar (DashboardWeekStrip), race_prediction, training_load, stats (DashboardStatsWidget) + AthleteMobileTabs, DashStickyTabsV3, DashFabAi, баннеры генерации/ошибки. Значимые хендлеры: `handleDndDragEnd` (L213) — insert/merge логика layout'а с сохранением; `draggedModuleId` (L265) — модуль для DragOverlay; `handleWorkoutPress` (L313) — onNavigate('calendar', {date, week, day}).

## `src/components/Dashboard/dashboardLayout.js` (108 строк)
Чистые функции работы с layout'ом модулей дашборда (массив строк по 1–2 id) + персист в localStorage. Потребитель — только мёртвый Dashboard.jsx.

### `orderToLayout(order)` — L3
Плоский порядок id → строки, спаривая соседние PAIRABLE-модули.

### `getDefaultLayout()` — L21
Дефолтная раскладка (goal_countdown; today+next; trend; calendar; PR; stats).

### `getStoredLayout()` — L32
Чтение из localStorage с миграцией старого плоского формата, валидацией id, дедупликацией и дополнением отсутствующих модулей; fallback — дефолт.

### `layoutToOrder(layout)` — L61
layout → плоский массив id.

### `saveLayout(layout)` — L65
JSON в localStorage (try/catch с console.warn).

### `layoutRemoveId(layout, id)` — L73
Убирает id, выкидывая опустевшие строки.

### `layoutInsertRow(layout, rowIndex, id)` — L82
Вставляет новую одиночную строку перед rowIndex.

### `layoutMergeIntoRow(layout, targetRowIndex, id)` — L86
Добавляет id вторым в одиночную строку.

### `layoutExpandSlot(layout, rowIndex, slotIndex)` — L94
Разворачивает слот парной строки в отдельную строку (другой остаётся выше).

### `expandLayoutForMobile(layout)` — L102
Все блоки по одному в строку.

## `src/components/Dashboard/DashboardMetricIcons.jsx` (11 строк)
Re-export иконок метрик из common/Icons под именами Metric*Icon.

### `MetricDistanceIcon` / `MetricTimeIcon` / `MetricActivityIcon` / `MetricPaceIcon` — L7–L10
Алиасы DistanceIcon/TimeIcon/CalendarIcon/PaceIcon соответственно.

## `src/components/Dashboard/DashboardStatsWidget.jsx` (168 строк)
Виджет статистики дашборда: переключатель периода (Месяц/3 мес/Год) + 4 metric-card (дистанция, время, тренировки, средний темп). Потребитель — только мёртвый Dashboard.jsx.

### `DashboardStatsWidget({api, onNavigate, viewContext=null})` — L12
`loadStats` (L18): параллельно-последовательные `api.getAllWorkoutsSummary(vc)`, `api.getAllResults(vc)`, `api.getPlan(null, vc)` с толерантной распаковкой ответов → `processStatsData(..., timeRange)` из Stats/StatsUtils. Скелетон при первой загрузке; клик по виджету → onNavigate('stats') (кнопки периода клик не пробрасывают).

## `src/components/Dashboard/DashboardWeekStrip.jsx` (244 строки)
Компактная полоска текущей недели для блока «Календарь» дашборда: 7 ячеек (дата, тип, иконки активностей) + легенда типов. Потребитель — только мёртвый Dashboard.jsx.

### `normalizeDayActivities(rawDayData)` — L13
День из API (объект или массив) → массив {type}.

### `firstNonRestType(activities)` — L20
Первый тип не rest/free (для класса ячейки).

### `getWeekDaysFromPlan(plan, progressDataMap)` — L25
Находит в plan.weeks_data неделю, содержащую сегодня (парсинг start_date как локальной даты), и строит 7 дней: dateStr, label, активности, isToday, status (completed/planned/rest), cellType, weekNumber.

### `getCellShortLabel(activities, status)` — L97
Короткая подпись ячейки: «Выполнено», тип из DAY_TYPE_LABELS (L83) с «· +N», «Отдых» или «—».

### `DashboardWeekStrip({plan, progressDataMap, onNavigate, onDayClick})` — L119
matchMedia ≤640px → мобильный режим (макс. 2 иконки + «…», на десктопе до 4). Клик по дню → onDayClick(date, weekNumber, dayKey) или onNavigate('calendar', {...}). Пустое состояние «Нет плана на текущую неделю» кликабельно в календарь. Легенда — LEGEND_ITEMS (L108). Иконки из Calendar/WeekCalendarIcons (RunIcon/OFPIcon/SbuIcon/CompletedIcon).

## `src/components/Dashboard/GoalCountdownWidget.jsx` (257 строк)
Виджет «Главная цель»: крупный счётчик дней до старта, дата, фаза макроцикла, карточки «Цель/Прогноз/Темп» с дельтой от цели. Используется и v3-дашбордом.

### `parseDate(iso)` — L42
'YYYY-MM-DD' → локальная Date без UTC-сдвига.

### `formatRaceDate(iso)` — L49
«<день недели>, D <месяц>».

### `formatTargetTime(time)` — L56
'HH:MM:SS'/'MM:SS' → «1ч 02м» / «42м 15с» / «Nс».

### `formatPace(pace)` — L68
Тривиальный trim строки темпа.

### `parseTimeToSec(time)` — L73
'HH:MM:SS'/'MM:SS' → секунды или null.

### `formatPaceFromSec(secPerKm)` — L81
Секунды/км → «M:SS».

### `formatGoalDelta(predictionSec, targetSec)` — L89
Дельта прогноза от цели: {text: '±Xм YYс от цели'|'на уровне цели', tone: up/down/neutral} (порог 5с).

### `getCurrentWeekPhase(plan)` — L108
Фаза (base/build/peak/...) недели plan.weeks_data, содержащей сегодня.

### `GoalCountdownWidget({api, plan, onNavigate})` — L126
Загружает `api.getRacePrediction()`; не рендерится без goal или при days_to_race ≤ 0. Показывает дистанцию (DISTANCE_LABELS L11), фазу (PHASE_LABELS L29), дни/недели до старта, целевое время + расчётный целевой темп (DISTANCE_KM L20), прогноз и дельту. Клик → onNavigate('calendar').

## `src/components/Dashboard/PersonalRecordsWidget.jsx` (123 строки)
Личные рекорды за 52 недели: 4 карточки (5K/10K/Полу/Марафон) с временем, темпом, датой и VDOT; пустые карточки с «—».

### `formatTime(sec)` — L20
Секунды → «H:MM:SS»/«M:SS» или «—».

### `formatPace(sec)` — L29
Секунды/км → «M:SS».

### `formatDate(iso)` — L36
'YYYY-MM-DD' → «D мес YY».

### `PersonalRecordsWidget({api})` — L43
Загружает `api.getPersonalRecords()`, индексирует по distance_label и рендерит карточки BUCKETS (L11); скелетон при загрузке.

## `src/components/Dashboard/PlanGeneratingState.jsx` (44 строки)
Полноэкранное (в пределах дашборда) состояние «План генерируется» — AI-марка с кольцом, прогресс-бар, чек-лист шагов; согласован с онбордингом StepGenerating. Используется CalendarScreen и DashboardV3.

### `PlanGeneratingState({label})` — L19
Рендерит заголовок (label или «Собираю твой план»), подсказку «3–5 минут», бар и статичные GEN_STEPS (L12: done/done/active/todo).

## `src/components/Dashboard/ProfileQuickMetricsWidget.jsx` (144 строки)
Виджет быстрых метрик для профиля за 7 дней: прогресс недели «X из Y» + бар + карточки дистанция/активность/время. Потребителей в src нет (мёртвый).

### `ProfileQuickMetricsWidget({api, viewContext=null, plan=null, progressDataMap=null, weekProgress={completed:0,total:0}})` — L13
`loadStats` (L17): те же `api.getAllWorkoutsSummary`/`getAllResults`/`getPlan` → `processStatsData(..., 'last7days')` (копия логики DashboardStatsWidget). Прогресс-карточка считается из пропса weekProgress; пропсы plan/progressDataMap не используются.

## `src/components/Dashboard/RacePredictionWidget.jsx` (342 строки)
Виджет прогноза на забег (VDOT + Riegel): значение VDOT с источником, таблица прогнозов по дистанциям, тренировочные зоны. В compact-режиме — две свайпаемые страницы. Потребитель — только мёртвый Dashboard.jsx (v3 использует RacePredictionV3).

### `RacePredictionWidget({api, viewContext=null, compact=false})` — L57
Загрузка `api.getRacePrediction(viewContext)` (`load` L69); перезагрузка по версии useWorkoutRefreshStore. useMediaQuery(≤1023px) → короткие подписи дистанций (DISTANCE_LABELS_SHORT L23). Состояния: loading (LogoLoading), error, `!data.available`. Подпись источника VDOT (benchmark_override/last_race/best_result/...). Compact-карта: пагинация прогнозы↔зоны со свайпом (`handleSwipeStart`/`handleSwipeEnd`, порог 50px) и кнопками-стрелками; ключи compact-таблицы через COMPACT_TABLE_KEY_GROUPS (L48) с `isCompactTargetKey` (синонимы half/21.1k, marathon/42.2k). Полный режим: две колонки — таблица (VDOT, опц. Riegel, темп; подсветка целевой дистанции) + зоны (PACE_ZONE_LABELS/COLORS L32/L40).
