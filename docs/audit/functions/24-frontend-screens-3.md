# Frontend screens 3/3 (Settings, Stats, Templates, Trainers, UserProfile) — справочник

## `src/screens/SettingsScreen.jsx` (1550 строк)
Контейнер экрана настроек: держит всё состояние (профиль, интеграции, уведомления, биометрия, Telegram) и собирает контекст `settingsCtx` для рендера через `SettingsV3`. Подключён в роутинг (`AppTabsContent` → таб `/settings`) и в выезжающую панель (`SettingsPanel` с `inPanel`).

### `detectIOSDevice()` — L36
Определяет iOS-устройство по userAgent/platform (включая iPad с MacIntel + maxTouchPoints).

### `getTelegramLinkPendingTimestamp()` — L47
Читает из localStorage метку времени начала привязки Telegram; чистит просроченные (>30 мин) и невалидные значения, возвращает timestamp или null.

### `markTelegramLinkPending()` — L75
Пишет в localStorage текущий timestamp как маркер «привязка Telegram в процессе».

### `clearTelegramLinkPending()` — L87
Удаляет маркер привязки Telegram из localStorage.

### `getBrowserNotificationRecoveryText(permission)` — L99
Возвращает текст-подсказку для состояний разрешения браузерных уведомлений ('denied'/'default').

### `BrowserWindowIcon({className, size, ...props})` — L111
SVG-иконка окна браузера (для канала web_push в `channelMeta`).

### `SettingsScreen({onLogout, inPanel})` — L133
Главный компонент-контейнер. Пропсы: `onLogout` (колбэк выхода), `inPanel` (рендер в выезжающей панели — локальная навигация `panelCat` вместо URL `?tab=`). Рендерит сообщения об успехе/ошибке, отладку Strava, `SettingsV3 ctx={settingsCtx}` и `PinSetupModal`; при загрузке — `SkeletonScreen type="settings"`. Сторы/хуки: `useAuthStore` (api, user, updateUser, logout), `useWorkoutRefreshStore` (через useSettingsActions/syncProvider), `useIsTabActive`, `useMediaQuery`, `useSwipeableTabs`, `useSearchParams`, `useHealthConnect`, `useMyCoaches`, `useCoachPricing`, `useSettingsProfile`, `useSettingsActions`; сервисы `BiometricService`, `PinAuthService`, `WebPushService`, `isNativeCapacitor`. API: `validateField`, `getIntegrationsStatus`, `getIntegrationOAuthUrl`, `syncWorkouts`, `unlinkIntegration`, `setSuuntoMirror`, `getStravaTokenError`, `send_test_notification`, плюс методы из под-хуков.
Значимые внутренние хендлеры/эффекты:
- `checkSlugAvailability` (L228) — проверка свободности username через `validateField`, свой текущий slug считается свободным.
- `stopTelegramLoginPolling`/`refreshTelegramConnection`/`handleTelegramConnected`/`startTelegramLoginPolling` (L278–350) — поллинг (3 с, таймаут 180 с) привязки Telegram через silent `loadProfile`.
- эффект OAuth-callback (L353) — обработка `?connected=huawei|strava|polar|garmin|coros|suunto|telegram` и `?error=`, автозапуск синка Strava/Huawei, показ stravaDebug.
- эффект загрузки статуса интеграций при `effectiveTab==='integrations'` (L420), эффект статуса web push permission (L435), листенер postMessage `planrun:telegram-login` (L457), resume-поллинг Telegram по focus/visibility (L495), статус биометрии/PIN на нативе (L529), загрузка профиля (L549).
- `handleTabChange` (L565) — смена `?tab=` + scroll-to-top на мобильном.
- `openTelegramBot` (L572) — генерирует/переиспользует код привязки, открывает диплинк бота (Capacitor Browser на нативе, popup в вебе), запускает поллинг.
- автосейв formData с debounce 350 мс (L638), beforeunload-предупреждение при несохранённом (L650).
- `handleLogout` (L661), `toggleDay` (L671) — переключение дня недели + автообновление sessions_per_week.
- `updateNotificationSettings`/`updateNotificationPreference`/`updateNotificationTime`/`updateQuietHours`/`updatePaused` (L734–789) — иммутабельные апдейты notification_settings.
- `refreshCurrentBrowserWebPushState` (L791), `handleBrowserNotificationPermission` (L811), `syncWebPushSubscription` (L854), `disconnectCurrentBrowserWebPush` (L906) — жизненный цикл web push подписки текущего браузера.
- `getWebPushSetupState` (L950) — конечный автомат состояний настройки web push (server_unavailable/install_required/unsupported/…/connected) с label+action.
- `ensureNotificationChannelReady` (L1067) — пред-проверка/инициализация канала перед включением (web_push → permission+subscribe, mobile_push → registerPushNotifications, telegram/email → проверка привязки).
- `handleNotificationPreferenceToggle` (L1183), `getEventChannelState` (L1267) — состояние чекбокса матрицы событие×канал.
- `onThemeChange` (L1313), `connectProvider` (L1334, с поллингом Strava-попапа), `syncProvider` (L1376), `unlinkProvider` (L1393), `onSetSuuntoMirror` (L1407), `onTestNotification` (L1423), `onResetNotifications` (L1437), `setCat` (L1460).

## `src/screens/settings/settingsUtils.js` (19 строк)
Утилиты темы и список валидных вкладок настроек.

### `VALID_TABS` — L1
Массив допустимых значений `?tab=`: profile, training, notifications, social, integrations, look, security.

### `getSystemTheme()` — L3
Возвращает 'dark'/'light' по media query `prefers-color-scheme`.

### `getThemePreference()` — L7
Читает сохранённую тему из localStorage; иначе 'system'.

### `applyTheme(theme)` — L12
Ставит `data-theme` на html/body, обновляет meta theme-color и ссылку на webmanifest (light/dark вариант). Также используется в `services/telegramMiniApp.js`.

## `src/screens/settings/useCoachPricing.js` (63 строки)
Хук состояния тарифов тренера (вкладка «Тренеры» в настройках).

### `useCoachPricing(api, setMessage)` — L3
Возвращает `{coachPricing, coachPricingLoading, savingPricing, loadCoachPricing, handleAddPricingItem, handlePricingChange, handleRemovePricingItem, handleSavePricing}`. API: `getCoachPricing`, `updateCoachPricing`. Добавление создаёт пустой тариф `{type:'individual', currency:'RUB', period:'month'}`; сохранение шлёт массив без локальных id.

## `src/screens/settings/useMyCoaches.js` (35 строк)
Хук списка «мои тренеры» атлета.

### `useMyCoaches(api, setMessage)` — L3
Возвращает `{myCoaches, myCoachesLoading, removingCoachId, loadMyCoaches, handleRemoveCoach}`. API: `getMyCoaches`, `removeCoach` (с window.confirm).

## `src/screens/settings/useSettingsActions.js` (362 строки)
Хук действий настроек: синки интеграций, блокировка PIN/биометрия, аватар, Telegram. Принимает api/csrfToken и ~13 сеттеров состояния контейнера.

### `useSettingsActions({api, csrfToken, ...setters, updateUser})` — L8
Возвращает 11 колбэков:
- `runStravaSync(apiClient)` (L25) — `syncWorkouts('strava')` после подключения + `triggerRefresh` воркаутов.
- `runHuaweiSync(apiClient, announceConnected)` (L42) — аналогично для Huawei с вариативными текстами.
- `handleEnableLock` (L72) — проверяет доступность PIN (только натив), берёт access/refresh токены и открывает PinSetupModal.
- `handlePinSetupSuccess` (L96) — фиксирует включение PIN, закрывает модалку.
- `handleAddFingerprint` (L103) — checkAvailability → authenticate (с таймаутом 15 с) → saveTokens в Secure Storage; ставит biometricEnabled.
- `handleDisableLock` (L157) — `PinAuthService.clearPin()` + `BiometricService.clearTokens()`.
- `ensureCsrfToken(apiClient)` (L172) — ленивое получение CSRF-токена (внутренний, не возвращается наружу).
- `handleAvatarUpload(event)` (L182) — multipart upload на `api_wrapper.php?action=upload_avatar` c Bearer-токеном, обновляет formData и user в сторе.
- `handleRemoveAvatar` (L242) — POST `remove_avatar`, чистит avatar_path в formData и сторе.
- `handleUnlinkTelegram` (L269) — POST `unlink_telegram` с confirm.
- `handleStartTelegramLogin(options)` (L296) — GET `telegram_login_url`, возвращает `{authUrl}`. ПОТРЕБИТЕЛЕЙ НЕТ (см. suspected_dead).
- `handleGenerateTelegramLinkCode` (L320) — POST `generate_telegram_link_code`, возвращает `{code, expiresAt}`.

## `src/screens/settings/useSettingsProfile.js` (254 строки)
Хук загрузки/сохранения профиля и настроек уведомлений.

### `useSettingsProfile({api, formData, setFormData, ...})` — L6
Возвращает `{loadProfile, handleInputChange, handleSave}`:
- `loadProfile(apiClient, {silent})` (L18) — параллельно `get_csrf_token` + `get_profile` + `get_notification_settings`; маппит через `mapProfileToFormData`, нормализует notification_settings, ставит `skipNextAutoSaveRef` (чтобы автосейв не сработал), возвращает новый formData или null.
- `handleInputChange(field, value)` (L71) — обновление поля formData (null/undefined → ''), ставит hasUnsavedChanges.
- `handleSave()` (L81) — валидация email, ленивый CSRF, POST `update_profile` (~45 полей; пустые шлются как `''`, не null — иначе бэк откатывал значение), затем POST `update_notification_settings`; при успехе нормализует formData из ответа только если formData не изменился с момента снапшота (защита от гонки с автосейвом), обновляет user в `useAuthStore`. При ошибке только notification-части — частичный merge + error-сообщение.

## `src/screens/settings/v3/catalog.jsx` (22 строки)
Каталог категорий настроек v3: id, tab (для deep-link `?tab=`), заголовок, иконка, компонент секции.

### `CATS` — L11
Массив 7 категорий: profile, training, notif(→notifications), coaches(→social), integ(→integrations), look, security.

### `catById(id)` — L21
Поиск категории по внутреннему id.

### `catByTab(tab)` — L22
Поиск категории по значению `?tab=`.

## `src/screens/settings/v3/icons.jsx` (14 строк)
Монохромные line-иконки настроек v3 (stroke = currentColor, 20×20).

### `wrap(children, vb)` — L2
Внутренний хелпер-обёртка SVG (не экспортируется).

### `ProfileIcon` — L6, `TrainingIcon` — L7, `NotifIcon` — L8, `IntegIcon` — L9, `LookIcon` — L10, `SecurityIcon` — L11, `CoachesIcon` — L12, `ChevronIcon` — L13, `BackIcon` — L14
Иконки-компоненты без пропсов; используются в каталоге, primitives и SettingsV3.

## `src/screens/settings/v3/primitives.jsx` (118 строк)
iOS-style grouped-list примитивы для секций настроек v3.

### `Group({label, children, footer})` — L5
Группа строк с заголовком/футером; вставляет divider между непустыми детьми.

### `Row({children, onClick, className, column})` — L23
Базовая строка; при onClick добавляет role=button, tabIndex и Enter/Space-обработку.

### `NavRow({title, sub, onClick})` — L35
Строка-переход с шевроном.

### `FieldRow({label, children})` — L47
Строка «лейбл слева + контрол справа».

### `Toggle({on, onChange, disabled})` — L56
Переключатель (role=switch).

### `ToggleRow({label, sub, on, onChange, disabled})` — L70
Строка с заголовком/подписью и Toggle справа.

### `Seg({options, value, onChange})` — L82
Сегмент-контрол из пар `[id, label]`.

### `DayPicker({days, value, onToggle, variant})` — L99
Кнопки дней недели с aria-pressed; variant 'run'/'ofp' меняет цвет активного.

## `src/screens/settings/v3/sections/CoachesSectionV3.jsx` (94 строки)
Секция «Тренеры»: список моих тренеров, переход к поиску, страница тренера и тарифы (для coach/admin).

### `CoachesSectionV3({ctx})` — L4
Рендерит Group «Мой тренер» (список myCoaches с кнопкой «Отвязать»), NavRow «Найти тренера» (onFindTrainer), для isCoachRole — NavRow редактирования страницы тренера и редактор тарифов (select типа/периода, инпуты цены, добавить/удалить/сохранить). Всё из ctx: `myCoaches, myCoachesLoading, removingCoachId, onRemoveCoach, onFindTrainer, isCoachRole, coachPricing, …, onSavePricing`. Использует `getDisplayName`.

## `src/screens/settings/v3/sections/IntegrationsSectionV3.jsx` (97 строк)
Секция «Интеграции»: провайдеры (Strava/Polar/Garmin/COROS/Suunto/Huawei), Telegram, Health Connect, Suunto-зеркало.

### `PROVIDERS` — L3
Конфиг 6 провайдеров (id, name, logo, detail, sync).

### `ProviderRow({name, logo, letter, detail, connected, syncing, onConnect, onSync, onUnlink, busy})` — L12
Строка провайдера: логотип/буква, точка «подключён», кнопки Синхр./Отключить либо Подключить.

### `IntegrationsSectionV3({ctx})` — L39
Маппит PROVIDERS в ProviderRow (статус из `integrationsStatus`, действия `connectProvider/syncProvider/unlinkProvider`), отдельные строки для Telegram (`onConnectTelegram/onUnlinkTelegram`, busy=isTelegramConnecting) и Health Connect (из `hc`), плюс ToggleRow зеркалирования в Suunto (`suuntoMirror`, `onSetSuuntoMirror`).

## `src/screens/settings/v3/sections/LookSectionV3.jsx` (25 строк)
Секция «Внешний вид»: выбор темы.

### `THEMES` — L3
Три темы (light/dark/system) со свотчами-градиентами.

### `LookSectionV3({ctx})` — L9
Радио-список тем; активная по `ctx.themePreference`, смена через `ctx.onThemeChange`.

## `src/screens/settings/v3/sections/NotifSectionV3.jsx` (110 строк)
Секция «Уведомления»: «не беспокоить», setup web push, расписание/тихие часы, матрица событие×канал, тест/сброс.

### `Check({state, onToggle})` — L3
Ячейка матрицы: '—' если канал не поддерживается событием, иначе кнопка-чекбокс (disabled из state).

### `NotifSectionV3({ctx})` — L19
Рендерит ToggleRow паузы (`updatePaused`), карточку webPushSetupState (summary + action), time-инпуты расписания (`updateNotificationTime`) и тихих часов (`updateQuietHours`), группы `visibleNotificationGroups` с шапкой каналов (`availableChannels`/`channelMeta`, CTA «подключить» для Telegram → `goToTab('integrations')`) и ячейками `getEventChannelState`/`onToggleNotification`, кнопки `onTestNotification`/`onResetNotifications`.

## `src/screens/settings/v3/sections/ProfileSectionV3.jsx` (128 строк)
Секция «Профиль»: аватар, личные данные, адрес профиля (slug), приватность, здоровье.

### `PROFILE_VIS` — L3
Опции видимости профиля (public/link/private).

### `TIMEZONES` — L4
9 пар [tz, label] для select часового пояса (плюс fallback-option для нестандартного tz).

### `ProfileSectionV3({ctx})` — L16
Рендерит аватар с upload-инпутом (`onAvatarUpload`/`onRemoveAvatar`), поля имя/фамилия/пол(Seg)/дата рождения(month-инпут → birth_year+birth_month)/рост/вес/таймзона через `onField`, проверку slug (`onCheckSlug`, статус в footer), Seg видимости + 4 ToggleRow приватности, textarea health_notes.

## `src/screens/settings/v3/sections/SecuritySectionV3.jsx` (83 строки)
Секция «Безопасность»: email, смена пароля, PIN/биометрия, удаление аккаунта (опционально), выход.

### `SecuritySectionV3({ctx})` — L3
Email-инпут (`onField`), строка «Сменить пароль» (`onChangePassword`), блок блокировки приложения при `showBiometricSection`: включение PIN (`onEnableLock`), отключение (`onDisableLock`), добавление отпечатка (`onAddFingerprint`) — состояния `pinEnabled/biometricEnabled/biometricAvailable/…`; строки «Удалить аккаунт» (`onDeleteAccount`, в текущем ctx не передаётся) и «Выйти» (`onLogout`).

## `src/screens/settings/v3/sections/TrainingSectionV3.jsx` (181 строка)
Секция «Тренировки»: режим (ai/coach/self), цель, опыт/объём, график дней, темп, последний забег.

### `MODE_FOOTER` — L5
Подписи-футеры для трёх режимов тренировок.

### `DIST` — L10
Опции дистанций гонки (5k/10k/half/marathon).

### `TrainingSectionV3({ctx})` — L12
Через `onField` редактирует training_mode, goal_type и условные блоки: race/time_improvement (дистанция/дата/целевое время), weight_loss (целевой вес/дата), health (программа/срок); уровень/база/дата старта/дорожка; DayPicker дней бега и ОФП (`onToggleRunDay`/`onToggleOfpDay`, дни из `profileForm.daysOfWeek`); лёгкий темп через `formatPaceMask`/`paceMaskToSeconds` (внутренний `setPace`, валидный диапазон 180–600 сек); для goal='race' — флаг первого забега и группа «Последний забег» (для VDOT).

## `src/screens/settings/v3/SettingsDesktopV3.jsx` (29 строк)
Десктоп-layout настроек v3: левый rail категорий + контент секции.

### `SettingsDesktopV3({ctx})` — L3
Рендерит кнопки CATS в aside (активная по `ctx.activeCat`, смена через `ctx.setCat`), заголовок и `<Section ctx>` текущей категории (fallback — первая).

## `src/screens/settings/v3/SettingsV3.jsx` (64 строки)
Точка входа UI настроек v3: выбирает desktop или mobile drill-in layout.

### `MobileSettingsV3({ctx})` — L8
Одноколоночный drill-in: без активной категории — карточка профиля (аватар, имя, @username, режим) + список CATS; с категорией — шапка с BackIcon и `<cat.Component ctx>`.

### `SettingsV3({ctx, layout})` — L59
При `layout!=='drill'` и `useMediaQuery('(min-width:1024px)')` → SettingsDesktopV3, иначе MobileSettingsV3. `layout='drill'` используется выезжающей панелью.

## `src/screens/StatsScreen.jsx` (246 строк)
Контейнер экрана статистики `/stats`: грузит сырые данные (summary/list/results/plan) и рендерит `StatsV3` (components/Stats/v3) + WorkoutSheet/ResultModal. Для тренера — селектор атлета (`?athlete=slug`).

### `StatsScreen()` — L16
Сторы/хуки: `useAuthStore` (api, user), `usePreloadStore` (preloadTriggered — фоновая предзагрузка на нативе), `useWorkoutRefreshStore` (version → silent-перезагрузка с debounce 250 мс), `useIsTabActive`, `useLocation/useNavigate`. API: `getCoachAthletes`, `getAllWorkoutsSummary`, `getAllWorkoutsList(…, 500)`, `getAllResults`, `getPlan`, `getDay`, `deleteWorkout`. Рендерит: AthleteSelect (coach), баннер «Режим тренера», `StatsV3 rawData={...}`, `WorkoutSheet` (canEdit только для своих данных), `ResultModal` редактирования дня; состояния — SkeletonScreen / «нет API» / «нет данных».
Значимые хендлеры: `loadRawData` (L50) — Promise.allSettled четырёх запросов с нормализацией форм ответов; `handleWorkoutClick` (L130) — мгновенно открывает sheet и обогащает тренировку из `getDay` по id; `handleEditWorkout` (L150), `handleDeleteWorkout` (L156) — удаление + silent reload + triggerRefresh; эффект первичной загрузки с учётом preload (L100); перезагрузка при смене атлета (L116).

## `src/screens/TemplatesScreen.jsx` (175 строк)
Экран `/library` (coach/admin): управление шаблонами тренировок тренера — сетка карточек, создание/редактирование через TemplateEditorModal, удаление.

### `TemplatesScreen()` — L18
Сторы: `useAuthStore` (api), `useCoachStore` (templates, reloadTemplates). API: `listExerciseLibrary` (библиотека упражнений для редактора), `saveWorkoutTemplate`, `deleteWorkoutTemplate`. Рендерит шапку с CTA «Создать шаблон», empty-state, карточки шаблонов (иконка `getTemplateIcon`, цвет/лейбл типа из `workoutTypes`, дистанция, счётчик использований, описание, список упражнений, кнопки Редактировать/Удалить) и `TemplateEditorModal`. Хендлеры: `handleSave` (L47) — сохранение + reload + закрытие; `handleDelete` (L63) — confirm + удаление + reload.

## `src/screens/trainers/FindTrainerV3.jsx` (175 строк)
Каталог тренеров v3 для атлета: фильтры по специализации, тогл «только принимающие», карточки тренеров с ценой/опытом/тегами.

### `SPEC_LABELS` — L9
Словарь лейблов специализаций (11 шт.).

### `FILTERS` — L16
Список чипов-фильтров (all + 5 специализаций).

### `CURRENCY` — L18
Символы валют rub/usd/eur.

### `minPrice(pricing)` — L20
Минимальная цена из тарифов тренера с символом валюты, локализованная строка или null.

### `FindTrainerV3({onPick})` — L28
Пропс `onPick(t)` — опциональный колбэк выбора (иначе navigate на `/{slug}`). Стор `useAuthStore` (api); API: `listCoaches({limit:50})`. Рендерит фильтры-чипы, тогл acceptingOnly, счётчик с плюрализацией, карточки (CoachAvatar, имя `getDisplayName`, опыт, статы, био, теги, цена или «по запросу», баннер «не берёт учеников»), кнопку «Стать тренером» → `/trainers/apply`.

### `Stat({label, value, sub})` — L149
Мини-блок статистики в карточке тренера.

### `yearsWord(n)` — L161
Русская плюрализация «год/года/лет».

### `trainersWord(n)` — L169
Русская плюрализация «тренер/тренера/тренеров».

## `src/screens/TrainersScreen.jsx` (314 строк)
Раздел «Тренеры»: для role=user — рендерит `FindTrainerV3`; для coach — табы «Группы»/«Запросы»; для admin — плюс «Каталог» и «Мои ученики».

### `GOAL_LABELS` — L20, `LEVEL_LABELS` — L21
Лейблы цели и уровня для карточек заявок.

### `timeAgo(dateStr)` — L23
Относительное время («только что», «N мин назад», … «вчера», дата) для заявок.

### `SPEC_LABELS` — L37
Лейблы специализаций для admin-каталога (дубль набора из FindTrainerV3 с другими формулировками).

### `TrainersScreen()` — L43
Сторы/хуки: `useAuthStore` (user, api), `useSwipeableTabs` (свайп между табами), useLayoutEffect+ResizeObserver для анимированной «pill» активного таба. API: `listCoaches`, `getCoachAthletes`, `getCoachRequests({status:'pending'})`, `acceptCoachRequest`, `rejectCoachRequest`. Рендерит табы (бейдж количества запросов), admin-каталог тренеров (Link-карточки с аватаром/био/тегами/ценой «от …»), список учеников (admin) с переходом в календарь атлета, `CoachGroupsView` (таб groups), карточки заявок (CoachAvatar, цель/уровень, сообщение, Принять/Отклонить). Значимые хендлеры: `loadCoaches` (L60), `loadAthletes` (L70), `loadRequests` (L80), `updateTrainersTabPill` (L100) — позиционирование pill по offsetLeft/offsetWidth активной кнопки; `handleAccept`/`handleReject` (L159/L166).

## `src/screens/UserProfileScreen.jsx` (510 строк)
Публичный/собственный профиль пользователя по slug (`/:username`): шапка (Public/Top), данные профиля + статистика/календарь/рекорды с учётом приватности, рендер через `ProfileV3`.

### `GOAL_TYPE_LABELS` — L27, `RACE_DISTANCE_LABELS` — L34
Лейблы цели и дистанции для текста цели.

### `formatGoalText(user)` — L41
Собирает строку цели профиля: для race/time_improvement — «Забег: Марафон, 12 окт. 2026, 3:30:00», иначе просто лейбл.

### `UserProfileScreen()` — L53
Сторы/хуки: `useAuthStore` (api, user, updateUser, setSettingsPanelOpen), `useWorkoutRefreshStore` (version → silent reload), `useParams/useLocation/useNavigate`; токен share-доступа из `?token=`. API: `getUserBySlug`, `getAllWorkoutsSummary`, `getAllWorkoutsList`, `getAllResults`, `getPlan`, `getPersonalRecords` (только владелец), `getDay`, `deleteWorkout`, `requestCoach`. Рендерит: `ProfileHeader` (TopHeader для залогиненных / PublicHeader с кнопками входа), `ProfileV3` (~25 пропсов: статы, план, прогресс-карта, неделя, приватность-флаги, колбэки), `DayModal` (read-only), `WorkoutSheet` (canEdit/onDelete для владельца), `BottomNav`, `LoginModal`/`RegisterModal` для гостей (RegisterModal с returnTo в чат).
Значимые хендлеры: `loadProfileStats` (L131) — грузит summary/list/results/plan с учётом privacy-флагов, считает через `processStatsData`, строит `progressDataMap` (статусы дней через `getPlanDayForDate`/`getDayCompletionStatus`) и `weekProgress` (находит текущую неделю плана, маппит дни на категории `planTypeToCategory` и матчит выполнение по `workoutTypeToCategory` из списка/результатов/агрегата); `handleWorkoutClick` (L310) — sheet + обогащение из getDay; `handleDeleteWorkout` (L329); `handleRequestCoach` (L343) — заявка тренеру; `handleMessage` (L357) — переход в чат с contactUser в state или RegisterModal для гостя; `ProfileHeader` (L373) — выбор шапки.
