# Frontend screens 2/3 (Coach*, Dashboard, DesignSystem, Landing, Onboarding, Register, Profile) — справочник

## `src/screens/coach/CoachGroupsView.jsx` (302 строки)
Экран управления группами тренера: список групп (rail) + детали активной группы (участники, средняя выполняемость), создание/редактирование/удаление групп, подбор участников и массовое назначение тренировок группе.

### `COLORS` — L9
Палитра из 8 hex-цветов для маркеров групп.

### `compliancePct(member, athletesById)` — L11
Считает процент выполнения недели атлета: `week_completed / week_total * 100` по данным из стора; возвращает `null` если данных нет.

### `CoachGroupsView()` — L19 (default export)
Пропсов нет. Рендерит двухколоночный layout: rail со списком групп (`List`) и детальную карточку активной группы (`Detail`) с участниками, статистикой и кнопкой bulk-assign; модалки `GroupEditor`, `MemberPicker`, `BulkAssignModal`. Сторы: `useAuthStore` (api), `useCoachStore` (groups/athletes/templates/loadAll). API: `getGroupMembers`, `saveCoachGroup`, `deleteCoachGroup`, `updateGroupMembers`, `bulkAssignTraining` (overwrite:false, без preflight-конфликтов). Значимые хендлеры: `saveGroup` (создание/апдейт группы + reload + установка activeId), `deleteGroup` (window.confirm + удаление), `setGroupMembers` (полная замена состава), `refreshMembers`.

### `GroupEditor({initial, busy, onCancel, onSave, onDelete})` — L235
Модалка создания/редактирования группы: input названия, сетка swatch-цветов, кнопки Удалить/Отмена/Сохранить. Локальный state name/color.

### `MemberPicker({athletes, memberIds, apiBaseUrl, busy, onCancel, onConfirm})` — L263
Модалка выбора участников: список всех атлетов с чекбоксами (Set в state, toggle), подтверждение передаёт массив id. Использует `CoachAvatar`, `getDisplayName`.

### `athletesWord(n)` — L296
Русская плюрализация слова «атлет» (атлет/атлета/атлетов).

## `src/screens/coach/CoachPageEditor.jsx` (258 строк)
Редактор публичной страницы тренера: био, философия, специализации, стаж, тарифы, сертификаты, видимость анкеты. Загружает и сохраняет профиль тренера через API.

### `SPEC_OPTIONS` — L8
11 пар [key, label] специализаций тренера (марафон, трейл, новички и т.д.).

### `PRICE_TYPES` — L15, `PRICE_PERIODS` — L16
Опции типа тарифа (индивидуально/группа/консультация/другое) и периода (месяц/неделя/разово/другое).

### `CoachPageEditor()` — L18 (default export)
Пропсов нет. Рендерит cover с аватаром, группы настроек (`Group`): видимость анкеты (ToggleRow «принимаю учеников», «верифицирован» — disabled, on только для admin), «О себе» (textarea с валидацией bio 100–500 символов), стаж, чипы специализаций, тарифы (динамический список строк price/period/type или «цена по запросу»), сертификаты; кнопка сохранения с состоянием «✓ Сохранено» 3 сек. Хуки: `useNavigate`, `useAuthStore` (api, user). API: `getMyCoachProfile` (load), `updateCoachProfile` + `updateCoachPricing` (save). canSave = bio валиден && ≥1 специализация. `preview()` ведёт на `/{slug}` публичной страницы.

### `Group({label, footer, children})` — L221
Обёртка группы настроек: лейбл сверху, тело, footer-подсказка.

### `Field({label, children})` — L231
Строка «лейбл + значение» внутри группы.

### `ToggleRow({label, sub, on, onChange, disabled})` — L240
Строка с switch-переключателем (кнопка с aria-pressed).

## `src/screens/CoachWorkspace.jsx` (509 строк)
Главный рабочий экран тренера: hero с приветствием и 4 KPI, переключение видов Таблица/Сетка/Поток, фильтры по группам/риску/свежим, bulk-assign с preflight-конфликтами, drill-in оверлей атлета, сравнение, групповые сообщения, quick reply на мобиле.

### `VIEW_TABS` — L32
Описание трёх вкладок вида: table/grid/stream c label и hint.

### `firstName(user)` — L38
Первое слово из `user.name` либо username.

### `formatTodayHeader(now)` — L44
Форматирует «среда · 9 июня» по ru-RU локали.

### `CoachWorkspace()` — L51 (default export)
Пропсов нет. Сторы: `useAuthStore` (api/user); `useCoachStore` — ~15 селекторов (athletes, groups, templates, view, filterGroup, activeAthleteId, loading, loadError, loadAll, setView, setFilterGroup, setActiveAthleteId, selected/toggleSelected/selectMany/clearSelected, bulkAssignOpen/open/close, events, reloadEvents) + мемо-селекторы `selectFilteredAthletes`, `selectKpi`; `useWorkoutRefreshStore` (version → reload при глобальном refresh). Поведение: `loadAll(api)` при маунте и при refreshVersion; polling `reloadEvents` каждые 60с; синк `?view=` и `?athlete=slug&panel=open` ↔ state (browser back закрывает overlay). Рендерит: hero (greeting по часам + KpiCard×4), вкладки видов, FilterChip'ы (все/группы/риск/свежие), CTA «Шаблоны» (→/library) и «Назначить тренировку», основную область (`AthleteTable`/`AthleteGrid`/`EventStream`), `AthleteOverlay`, `BulkActionBar`, `CompareAthletesPanel`, `GroupMessageDialog` (onSend → `api.chatSendMessageToUser`), `EventQuickReplySheet` (мобильный bottom-sheet вместо overlay), `BulkAssignModal` (preflight `bulkAssignTraining` overwrite:false → при конфликтах `ConfirmConflictDialog` → повтор с overwrite:true). Значимые хендлеры: `handleSetView` (view+URL), `openAthlete`/`closeAthlete` (state+URL), два async onConfirm bulk-assign (~30 строк каждый).

### `buildSuccessNote(data)` — L453
Собирает строку итога bulk-assign («назначено N, перезаписано M, нет прав на K»).

### `KpiCard({label, num, tone, Icon})` — L461
KPI-карточка hero: иконка в тонированном круге + число; цвета из `TONE` (CoachPrimitives) через color-mix.

### `FilterChip({active, dot, onClick, children})` — L483
Кнопка-чип фильтра с опциональной цветной точкой.

### `athletesInGroup(athletes, groupId)` — L496
Число атлетов, у которых в `a.groups` есть группа с данным id.

### `pluralAtletov(n)` — L503
Русская плюрализация «атлет/атлета/атлетов» (дубль `athletesWord` из CoachGroupsView).

## `src/screens/DashboardScreen.jsx` (43 строки)
Тонкая обёртка-роут главного экрана: пробрасывает api/user и навигацию в `DashboardV3`.

### `DashboardScreen()` — L12 (default export)
Пропсов нет. Хуки: `useIsTabActive('/')`, `useNavigate`, `useLocation`, `useAuthStore` (api, user, planGenerationMessage). Рендерит `<Dashboard>` (v3) с `onNavigate` (calendar — со state-параметрами), `registrationMessage` (из location.state.planMessage или стора) и `isNewRegistration`.

## `src/screens/DesignSystemScreen.jsx` (1296 строк)
Живая документация дизайн-системы (admin-only, роут /design-system): все токены, цвета, типографика, кнопки, формы, карточки, пилюли, workout-карточки, модалки, glass, empty/loading state, иконки, анимации — с директивными Rule-блоками и сниппетами.

### `PRIMARY_SCALE` — L39, `GRAY_SCALE` — L40
Шкалы шагов 50–900 для primary/gray палитр.

### `SEMANTIC_TOKENS` — L42, `WORKOUT_COLORS` — L49, `WORKOUT_STRIPS` — L58
Списки семантических токенов, цветов типов тренировок и strip-цветов карточек.

### `SPACING_SCALE` — L70, `RADII` — L71, `SHADOWS` — L72, `TEXT_SCALE` — L73, `FONT_WEIGHTS` — L74
Шкалы отступов/радиусов/теней/размеров текста/весов шрифта для рендера секций.

### `Section({id, title, subtitle, children})` — L87
Секция документации с заголовком и якорем для TOC.

### `Swatch({tokenName, fallbackColor, label, sub})` — L99
Цветовой образец: чип с `var(token)` + подпись.

### `TokenCode({children})` — L112
Инлайн-код `<code class="ds-token">`.

### `Rule({title, children, snippet})` — L119
Директивный блок «Правило» с опциональным pre/code сниппетом — основной элемент kit'а.

### `WorkoutSheetDemo({onClose})` — L135
Демо bottom-sheet деталей тренировки: захардкоженные 9 сегментов (разминка/темп/восстановление), stat-карточки, сегментированный бар, AI-совет, кнопки «Перенести»/«Отметить выполнение».

### `DesignSystemScreen()` — L217 (default export)
Пропсов нет. Хуки: `useNavigate`, `useAuthStore` (user.role === 'admin' — иначе locked-карточка с кнопкой на главную). Локальный state: previewLoading, sheetOpen, confirmOpen, formModalOpen, popoverOpen, theme (scoped data-theme на корневом div, не трогает document). Рендерит header, плавающий переключатель темы, TOC (21 якорь) и секции: Принципы (11 правил), Цвета, Темы (side-by-side токены), Цвета текста, Типографика, Отступы, Скругления, Тени, Свечения, Кнопки (.btn-классы + loading-demo), Формы (ds-field), Карточки (.card), Пилюли, Workout card, Sheet (открывает WorkoutSheetDemo), Модалки (raw-HTML демо confirm/form модалок — сознательно без `<Modal>` из-за portal vs scoped theme; demo popover), Glass, Empty state, Loading (skeleton/spinner/banner), Иконки (19 lucide из общего pool), Анимация. API не использует.

## `src/screens/ForgotPasswordScreen.jsx` (73 строки)
Экран «Забыли пароль»: ввод email/логина, отправка ссылки сброса, экран успеха с подсказкой про спам.

### `ForgotPasswordScreen()` — L10 (default export)
Пропсов нет. Хук `usePasswordResetRequest` (loading/error/sent/sentToEmail/isCoolingDown/secondsLeft/requestReset). До отправки — форма с input и кнопкой (cooldown «Подождите N сек»); после — сообщение «Письмо отправлено на …, ссылка действительна 1 час». Ссылка «← Вернуться к входу» на /landing. Стили LoginScreen.css.

## `src/screens/LandingScreen.jsx` (311 строк)
Публичный лендинг: hero с CTA «Начать бесплатно», нав-бар (Войти / Стать тренером), модалки логина/регистрации, particles-фон, viewport-фиксы для мобильных.

### `detectIOSDevice()` — L11
Детект iOS по userAgent + случай iPadOS (`MacIntel` + maxTouchPoints>1).

### `LandingScreen({onRegister, registrationEnabled = true})` — L20 (default export)
Стор: `useAuthStore` (isAuthenticated). Хуки: useNavigate/useLocation/useSearchParams, MutationObserver на `data-theme` html для isDark (передаётся в `ParticlesBackground`). Effects: вычисление высоты visualViewport → CSS-переменные `--landing-screen-height/--landing-visual-offset-*/--landing-runtime-bottom-inset` на showcase (на iOS высота не фиксируется); класс `landing-showcase--ios`; открытие LoginModal по `location.state.openLogin` или `?openLogin=1` (с очисткой); при isAuthenticated — закрытие модалок и redirect на `/` (пока в полёте — `LogoLoading`). Хендлеры: `handleLogin`/`handleRegister` (setTimeout-открытие модалок), `handleCoachIntent` (авторизован → /trainers/apply; иначе RegisterModal с returnTo `/trainers/apply` + coachOnboarding state). Рендерит framer-motion nav, `LoginModal`, `RegisterModal`, hero (заголовок planRUN, feature-пилюли, CTA disabled при !registrationEnabled), hero-картинки desktop/mobile, футер со ссылкой /privacy.

## `src/screens/OnboardingFlow.jsx` (356 строк)
Полноэкранный wizard специализации после регистрации (замена SpecializationModal): режим → цель → профиль → (AI-оценка для забега) → экран генерации плана. Логика перенесена 1:1 из старого RegisterScreen.

### `STEP_LABELS` — L27, `BRAND_FEATURES` — L28
Подписи шагов wizard'а и список фич для бренд-панели (desktop).

### `buildSteps(formData, skipMode)` — L36
Возвращает массив шагов в зависимости от режима: self → ['mode','profile']; иначе ['mode','goal','profile'] + 'assess' для целей race/time_improvement; при skipMode (смена режима из дашборда) шаг 'mode' выфильтровывается.

### `OnboardingFlow()` — L48 (default export)
Пропсов нет. Стор: `useAuthStore` (api, updateUser, setPlanGenerationMessage, user); клиент = api || `getAuthClient()`. State: formData (seed из user при `location.state.mode` через `seedOnboardingFromUser`, иначе `createInitialOnboardingState`), index/dir (направление анимации), error, loading, assessment/assessLoading, phase ('form'|'generating'), planMessage, pendingUserRef. Эффект AI-оценки: debounce 800мс → `client.assessGoal({_assessment_context:'registration', ...поля цели/опыта})`, вердикт не блокирует прохождение (caution → кнопка «Всё равно создать план»). Хендлеры: `handleChange`/`handleToggleArray` (preferred_days синхронизирует sessions_per_week), `validateStep` (пошаговая валидация: режим, цель+даты по goal_type, имя/пол/опыт), `handleSubmit` (`completeSpecialization(buildSpecializationPayload(formData))` → `getCurrentUser` → phase 'generating'), `handleNext`/`handleBack`, `handleFinish` (updateUser + setPlanGenerationMessage + navigate '/' с registrationSuccess). Рендерит: при 'generating' — `StepGenerating`; иначе бренд-панель (desktop, прогресс шагов), stepper-сегменты, AnimatePresence со step-компонентами `StepMode`/`StepGoal`/`StepProfile`/`StepAssessment`, кнопки Назад/CTA.

## `src/screens/PrivacyPolicyScreen.jsx` (176 строк)
Публичная статическая страница политики конфиденциальности (12 разделов: данные, цели, ИИ-обработка, интеграции, права, удаление).

### `PrivacyPolicyScreen()` — L10 (default export)
Пропсов нет. Стор: `useAuthStore` (isAuthenticated — ссылки логотипа/«На главную» ведут на `/` либо `/landing`). Чисто статический JSX-контент, API не использует.

## `src/screens/profile/ProfileV3.jsx` (309 строк)
Презентационный компонент публичного профиля (атлет и тренер) дизайна v3: hero, цель, рекорды, форма/тренды, неделя, последние тренировки, достижения, тренеры; для тренера — selling-карточки (о тренере, тарифы, как работаем, CTA запроса). Данные деривируются теми же утилитами, что и StatsV3.

### `SPEC_LABELS` — L20, `LEVEL_LABELS` — L24, `RACE_LABELS` — L25, `HOW_STEPS` — L26
Словари подписей специализаций/уровней/дистанций и 3 шага «как мы будем работать».

### `daysUntil(dateStr)` — L32
Дней до даты (ceil от разницы с полуночью сегодня); null при невалидной дате.

### `fmtTime(sec)` — L39
Секунды → `H:MM:SS` или `M:SS`; '—' для пустых.

### `Avatar({user, api, size})` — L45
Аватар: img через `getAvatarSrc` (md/sm по size) либо div с инициалами `getInitials`.

### `Stat({v, l})` — L50
Пара «значение + подпись» для hero-статистики.

### `ProfileV3({api, currentUser, profileUser, access, coaches, profilePlan, progressDataMap, weekProgress, workoutsList, records, showTrainer, showCalendar, showMetrics, showWorkouts, goalText, onSettings, onMessage, onRequestCoach, requestingCoach, coachRequested, coachRequestError, onGuestAction, onWorkoutClick})` — L52 (default export)
Чистый презентационный компонент (без сторов, API только baseUrl для аватаров). Деривации useMemo: `processOverviewV3` (годовая статистика + recent), `processTrendsV3` (vdot), `computeAchievements` (бейджи), workoutsByDate. Ветка тренера: профиль считается тренерским при role coach/admin + coach_bio. Собирает блоки-элементы (Hero, Goal, Records, Form=TrendsSmallV3, Week=WeekSectionV3 compact, Recent=RecentList, Badges, Trainer (AI-бейдж и/или ссылки на тренеров), About/Pricing/How/RequestCta/CoachBadge, accessDenied) и раскладывает по колонкам main/side в зависимости от типа профиля; рендер через cloneElement с ключами. Видимость управляется флагами access (is_owner/can_view/is_coach) и show*-пропсами.

## `src/screens/RegisterScreen.jsx` (342 строки)
Минимальная регистрация (v3): email+пароль → 6-значный код из письма → авто-логин; используется и как роут /register, и внутри RegisterModal (embedInModal).

### `detectBrowserTimezone()` — L19
`Intl.DateTimeFormat().resolvedOptions().timeZone` с try/catch → null.

### `RegisterScreen({onRegister, embedInModal, onSuccess, onClose})` — L27 (default export)
Стор: `useAuthStore` (api, updateUser); клиент = api || `getAuthClient()`. Хук `useVerificationCodeFlow` (verificationStep form/code, verificationCode, codeAttemptsLeft, cooldown, handleRequest/ConfirmError, markCodeSent). API: `validateField('email')`, `sendVerificationCode`, `registerMinimal({email, password, verification_code, timezone})`. Хендлеры: `handleSubmit` (шаг form — валидация пароля ≥6 и email + отправка кода; шаг code — registerMinimal → updateUser(authenticated) → коллбэки onRegister/onSuccess → navigate '/' с registrationSuccess), `handleResend`, эффект автосабмита при 6 введённых цифрах (защита от повтора через autoSubmittedCodeRef). Рендерит: форму аккаунта (логотип вне модалки, поля email/пароль, ссылки «Войти»/privacy) либо шаг кода (скрытый input + 6 визуальных боксов, autoComplete one-time-code, resend с cooldown, счётчик попыток); оборачивает в `.rgv3-shell` если не embedInModal.

## `src/screens/ResetPasswordScreen.jsx` (124 строки)
Экран установки нового пароля по токену из письма (?token=).

### `ResetPasswordScreen()` — L12 (default export)
Пропсов нет. Хуки: useNavigate, useSearchParams (token), `useRetryCooldown`. API: `getAuthClient().confirmResetPassword(token, password)`. Валидация: пароль ≥6 и совпадение confirm. При успехе — экран «Пароль изменён» + redirect на /landing с openLogin через 2с. Ошибки через `getAuthErrorMessage`/`getAuthRetryAfter` (запуск cooldown при 429). Без токена — заглушка со ссылкой на /forgot-password.

## `src/screens/settings/notificationSettings.js` (323 строки)
Модуль модели настроек уведомлений: каталог событий по группам, дефолтные preferences, нормализация сырых настроек с бэка (каналы, расписание, тихие часы).

### `NOTIFICATION_CHANNELS` — L1 (export)
Массив 4 каналов: mobile_push, web_push, telegram, email.

### `DEFAULT_CATALOG` — L3
Каталог из 4 групп (workouts/chat/plan/system) с событиями: event_key, label, description, channels, опц. roles (admin/coach) и locked (системные email).

### `buildDefaultPreferences()` — L140
Строит дефолтные пер-событийные флаги: mobile_push включён для workout.reminder.tomorrow, chat.* и admin.new_user_message; email — только для locked; остальное off.

### `createInitialNotificationSettings(timezone = 'Europe/Moscow')` — L157 (export)
Возвращает полный дефолтный объект настроек: version, timezone, 4 канала (enabled/available/delivery_ready и канало-специфика), schedule (08:00/20:00), quiet_hours (off, 22:00–07:00), preferences, catalog, paused:false.

### `ensureNotificationChannelsEnabled(settings)` — L203 (export)
Возвращает копию настроек, где у всех 4 каналов принудительно `enabled: true` (миграция со старого UI-переключателя каналов).

### `toBoolean(value, fallback)` — L220
Парсит boolean из boolean/number/строк ('1','true','yes','on' и инверсий).

### `normalizeTime(value, fallback)` — L231
Валидирует `H:MM`/`HH:MM(:SS)` → `HH:MM`; fallback при невалиде.

### `normalizeNotificationSettings(rawSettings, timezone)` — L242 (export)
Главный нормализатор: merge сырых настроек с дефолтами — каталог с бэка (или дефолтный), preferences по каждому событию каталога через toBoolean (email locked → всегда true), каналы (web_push.subscription_items фильтруются/маппятся), schedule/quiet_hours через normalizeTime, paused.

## `src/screens/settings/profileForm.js` (241 строка)
Модуль формы профиля настроек: начальное состояние formData, маппинг данных пользователя с бэка в поля формы (нормализация времени, дат, дистанций, темпа).

### `normalizeValue(value)` — L3 (export)
null/undefined/''/'null' → null, иначе значение как есть. Внешних потребителей не найдено.

### `createInitialFormData()` — L10 (export)
Полный дефолтный объект формы профиля (~50 полей: персональные, цель, тренировки, privacy_*, push_*, notification_settings через createInitialNotificationSettings).

### `parsePreferredDays(value, key)` — L64
Парсит preferred_days из JSON-строки/массива/объекта `{run:[],ofp:[]}` по ключу; [] при ошибке.

### `formatTime(time)` — L81
Нормализация времени с бэка: `HH:MM:SS` валидируется; MySQL TIME-длительности типа `49:27:00` пересчитываются в укладывающийся HH:mm:ss; `M:MM` ≤6 часов остаётся как HH:mm, больше — трактуется как минуты:секунды длительности.

### `formatDate(date)` — L142
Пропускает только строгий `YYYY-MM-DD`, иначе ''.

### `normalizeRaceDistance(dist)` — L148
Приводит свободный ввод (включая русские «марафон», «42.2», «21») к 'marathon'|'half'|'10k'|'5k'|''.

### `normalizeExperienceLevel(level)` — L159
Допустимые beginner/intermediate/advanced/novice/expert, дефолт novice.

### `formatEasyPaceMinutes(easyPaceSec)` — L168
Секунды темпа → строка `M:SS`.

### `mapProfileToFormData(userData)` — L177 (export)
Маппит объект пользователя с бэка в formData: строковые касты, formatTime/formatDate/normalize*, parsePreferredDays('run'/'ofp'), privacy-флаги (!=0), push-настройки с clamp часов/минут, notification_settings — свежий createInitialNotificationSettings (реальные настройки нормализуются отдельно в useSettingsProfile).

### `daysOfWeek` — L233 (export)
Массив 7 дней недели {value:'mon'..., label:'Пн'...}; используется в TrainingSectionV3.
