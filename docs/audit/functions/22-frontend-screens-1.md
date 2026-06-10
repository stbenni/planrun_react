# Frontend screens 1/3 (Admin, Athletes, Calendar, Chat) — справочник

## `src/screens/AdminScreen.jsx` (1047 строк)
Админ-панель: 4 вкладки — пользователи (поиск/пагинация/роль/удаление/массовая рассылка), настройки сайта, шаблоны уведомлений (overrides поверх дефолтов), заявки тренеров (approve/reject). Доступ только role === 'admin', иначе редирект на `/`.

### `formatCoachSpecialization(value)` — L59
Маппит ключ специализации тренера на русскую метку через `COACH_SPECIALIZATION_LABELS`, фолбэк — само значение.

### `formatPricingType(value)` — L63
Метка типа услуги тренера (`COACH_PRICING_TYPE_LABELS`), фолбэк — значение или «Услуга».

### `formatPricingPeriod(value)` — L67
Метка периода оплаты («в месяц»/«в неделю»/«разово»), фолбэк — значение или пустая строка.

### `formatPricingValue(item)` — L71
Форматирует цену услуги: `Intl.NumberFormat('ru-RU')` + валюта (RUB → ₽) + период; «Цена не указана» при пустой/нечисловой цене.

### `renderApplicationField(label, value, className)` — L89
Хелпер-рендер секции заявки тренера (label + значение или «Не указано»). Возвращает JSX.

### `normalizeNotificationTemplateGroups(groups)` — L100
Нормализует ответ API шаблонов уведомлений: приводит группы/события к строгой форме (event_key, label, placeholders[], defaults{title/body/link/CTA}, overrides{...}, has_override, updated_at/by), отбрасывает группы без событий.

### `buildNotificationTemplateDrafts(groups)` — L140
Строит из групп карту `{event_key → {title_template, body_template, link_template, email_action_label_template}}` из overrides — стартовое состояние черновиков форм.

### `formatNotificationTemplateUpdatedAt(value)` — L154
Дата обновления шаблона в `toLocaleString('ru-RU')` (ДД.ММ ЧЧ:ММ); пустая строка при невалидной дате.

### `AdminScreen()` — L172 (default export)
Без пропсов. Сторы/хуки: `useAuthStore` (user, api), `useNavigate`, `useIsTabActive('/admin')`, `useSwipeableTabs` (свайп между вкладками, ignoreSelector для таблиц/инпутов). API: `get_csrf_token`, `getAdminUsers`, `updateAdminUser`, `deleteUser`, `chatAdminBroadcast`, `getAdminSettings`, `updateAdminSettings`, `getAdminNotificationTemplates`, `updateAdminNotificationTemplate`, `resetAdminNotificationTemplate`, `getCoachApplications`, `approveCoachApplication`, `rejectCoachApplication`. Ключевые состояния: `tab`, `csrfToken`, `users/usersTotal/usersPage/usersSearch(+debounced 400мс)`, `broadcast*` (модалка/контент/таргет all|page), `settings`, `notificationTemplateGroups/Drafts`, `coachApps`. Эффекты: редирект не-админа, загрузка данных по активной вкладке, авто-поллинг заявок тренеров каждые 60с + visibilitychange (только при активной вкладке/роуте). Рендер: tablist, ошибка/notice, таблица пользователей с select роли и кнопками чат/удалить, форма настроек, карточки шаблонов (placeholders, defaults, override-инпуты, Сохранить/Сбросить), карточки заявок тренеров, модалка рассылки.
Значимые хендлеры: `fetchCsrf` (L231), `loadUsers` (L240), `loadSettings` (L263, c `normOnOff` L261), `loadCoachApps` (L282), `loadNotificationTemplates` (L296), `handleApproveCoach` (L314), `handleRejectCoach` (L322, confirm), `handleUpdateUserRole` (L378), `handleBroadcast` (L392, all → null userIds, page → ids текущей страницы без себя), `handleDeleteUser` (L414, confirm), `handleSaveSettings` (L429), `handleTemplateDraftChange` (L455), `handleSaveNotificationTemplate` (L465), `handleResetNotificationTemplate` (L493).

## `src/screens/AthletesOverviewScreen.jsx` (598 строк)
Главный экран тренера: карточки учеников с compliance-баром, целью/забегом, группами и быстрыми действиями; секция «Требуют внимания» сверху; фильтр по группам и модалка управления группами.

### `daysAgo(dateStr)` — L21
Число полных дней с даты; `Infinity` для пустой даты.

### `formatGoalInfo(athlete)` — L42
Собирает строку «Цель · Дистанция · цель ЧЧ:ММ» из goal_type/race_distance/race_target_time (словари `GOAL_LABELS`, `DISTANCE_LABELS`); null если нечего показать.

### `formatRaceDate(dateStr)` — L56
Дата забега с относительным хвостом: «(прошёл)», «(сегодня!)», «(через N дн./нед.)».

### `formatLastActivity(dateStr)` — L69
«Сегодня»/«Вчера»/«N дн. назад»/локальная дата; «Нет данных» без даты.

### `pluralizeDays(n)` — L78
Русская плюрализация «день/дня/дней».

### `getAttentionReason(athlete)` — L94
Возвращает `{kind: 'inactive'|'compliance', label}` или null: нет активности вовсе, ≥7 дней без активности, либо compliance < 50% относительно `week_total_so_far` (запланировано к сегодня, чтобы не было ложных срабатываний в начале недели). Первое сработавшее правило.

### `needsAttention(athlete)` — L115
Булева обёртка над `getAttentionReason`.

### `getComplianceColor(completed, total)` — L119
CSS-цвет по проценту выполнения: ≥80% зелёный, ≥50% жёлтый, иначе красный; серый при total=0.

### `AthletesOverviewScreen()` — L127 (default export)
Без пропсов. Сторы: `useAuthStore` (api, user), `useNavigate`. API: параллельно `getCoachAthletes`, `getCoachRequests({status:'pending'})`, `getCoachGroups` в `loadData` (L138). Состояния: `athletes`, `requestsCount`, `sortBy` (activity|name|compliance), `groups`, `filterGroupId`, `showGroupModal`. Мемо: `filteredAthletes` (по группе), разбиение на `attentionAthletes/normalAthletes`, `sortedNormal` (по выбранной сортировке), `sortedAttention` (по убыванию давности активности). Рендер: заголовок + бейдж запросов (→ `/trainers`), чипы групп, `GroupsModal`, секции «Требуют внимания» и «Остальные/Все ученики» из `AthleteCard`. Empty-state при отсутствии учеников.

### `AthleteCard({athlete, navigate, api, attention, attentionReason})` — L334
Карточка ученика: аватар (Link на профиль `/{slug}`), имя + бейдж «Новое», давность активности, плашка причины внимания, цель/дата забега, compliance-бар с цветом, теги групп, кнопки Календарь (`/calendar?athlete=slug`)/Профиль/Написать (`/chat?contact=slug`). Вся карточка кликабельна (primary action — календарь), вложенные ссылки/кнопки гасят всплытие; есть keyboard-обработка Enter/Space.

### `GroupsModal({api, groups, athletes, onClose, onSave})` — L442
Модалка управления группами тренера: список групп (участники/удалить), создание группы (имя + цвет из `GROUP_COLORS`), режим редактирования состава (чекбоксы учеников). API: `saveCoachGroup`, `deleteCoachGroup`, `getCoachGroups`, `getGroupMembers`, `updateGroupMembers`. Состояния: `localGroups`, `editingGroup` ({id,name,color,memberIds}), `newGroupName/Color`, `saving`. После мутаций зовёт `onSave` (перезагрузка данных родителя) и перечитывает группы.
Значимые хендлеры: `handleCreateGroup` (L450), `handleDeleteGroup` (L465), `handleEditMembers` (L478, грузит состав), `toggleMember` (L488), `handleSaveMembers` (L498).

## `src/screens/CalendarScreen.jsx` (761 строка)
Экран календаря тренировок: загрузка плана + сводки тренировок/результатов/executed-дат, виды Неделя/Месяц (v3), режим тренера через `?athlete=slug`, модалки пересчёта/нового плана/очистки/readiness-check, модалки результата и добавления тренировки.

### `CalendarScreen({targetUserId, viewContext, canEdit, isOwner, canView, viewMode})` — L28 (default export)
Пропсы (все опциональны, для встраивания в публичный профиль): `targetUserId`, `viewContext` (externalViewContext), `canEdit/isOwner/canView` (external*), `viewMode` (externalViewMode — фиксирует режим). Сторы/хуки: `useAuthStore`, `usePlanStore` (recalculating/recalculatePlan/generatingNext/generateNextPlan/planReadinessCheck/submit/dismiss, plan, isGenerating, generationLabel), `usePreloadStore` (preloadTriggered), `useWorkoutRefreshStore` (version → silent reload c дебаунсом 250мс), `useIsTabActive('/calendar')`, `useLocation/useNavigate`, `isNativeCapacitor`. API: `getCoachAthletes` (селектор атлетов для тренера), `getUserBySlug` (атлет + access), `getPlan`, `getAllWorkoutsSummary`, `getAllWorkoutsList`, `getAllResults`, `getExecutedDates`, `clearPlan`, `deleteWorkout`. Ключевые состояния: `coachAthletes`, `athleteData/athleteLoading`, `plan`, `workoutsData/workoutsListByDate/executedByDate/resultsData` (агрегируются в `calendarData`), `resultModal`, `addTrainingModal`, `viewMode` (week|full), модалки `showRecalcConfirm/recalcReason`, `showNextPlanModal/nextPlanGoals`, `showClearPlanConfirm/clearingPlan`, readiness-поля (`readinessPainScore/Worsened/TechniqueChanged/Text`). Производные: `viewContext/canEdit/isOwner/canView` из athleteData.access либо пропсов, `calendarUserId`, `weeksData` (через `getWeeksData` L129 — weeks_data или phases[0].weeks_data), `hasPlan`, `isPlanCompleted` (последняя неделя плана закончилась до сегодня), `canManagePlan`. Эффекты: загрузка атлетов тренера, загрузка атлета по slug, первичный `loadPlan` (учитывает preload на нативе и silent-режим), закрытие модалок при уходе с роута, сброс readiness-полей при новом чеке. Рендер: скелетон/нет доступа/ошибка загрузки/`PlanGeneratingState` при генерации; `AthleteSelect` + баннер «Режим тренера», баннер «План завершён», 4 модалки (пересчёт с чипами-подсказками, новый план, readiness-check со шкалой боли 0–10 и сегментами да/нет, очистка), переключатель Неделя/Месяц, `WeekViewV3`/`MonthViewV3` (+ `PlanActionsMenuV3` при canManagePlan), `ResultModal`, `AddTrainingModal`.
Значимые хендлеры: `loadPlan(options)` (L258 — 5 параллельных запросов, нормализация сводок/списков/результатов/executed-карт, запись плана в стор), `handleOpenRecalc` (L154), `handleRecalculate` (L159), `handleOpenNextPlan` (L169), `handleGenerateNextPlan` (L174), `handleClearPlan` (L184), `handlePlanPrimaryAction` (L200 — пересчёт или новый план по `isPlanCompleted`), `handleSubmitReadinessCheck` (L208), `handleV3EditDay` (L345), `handleV3MarkDone` (L351), `handleV3EditResult` (L354), `handleV3DeleteResult` (L374, confirm + deleteWorkout).

## `src/screens/chat/chatConstants.js` (23 строки)
Константы вкладок чата, фабрика id личного диалога и дескрипторы системных чатов; реэкспорт иконок.

### `TAB_AI` — L4
Константа id вкладки AI-чата (`'ai'`).

### `TAB_ADMIN` — L5
Id вкладки «От администрации» (`'admin'`).

### `TAB_ADMIN_MODE` — L6
Id админ-режима ответов пользователям (`'admin_mode'`).

### `TAB_USER_DIALOG` — L7
Id временной вкладки личного диалога до резолва контакта (`'user_dialog'`).

### `dialogId(userId)` — L9
Строит id вкладки личного диалога: `dialog_<userId>`.

### `SYSTEM_CHATS` — L11
Массив дескрипторов системных чатов (AI-тренер с `BotIcon`, администрация с `MailIcon`): id/label/Icon/description.

### `ADMIN_CHAT` — L16
Дескриптор вкладки «Администраторский» (`UsersIcon`) для админов.

Реэкспорт `MessageCircle, BotIcon, MailIcon, UsersIcon` — L23 (BotIcon снаружи не импортируется).

## `src/screens/chat/chatQuickReplies.js` (82 строки)
Контекстные quick-reply кнопки по тексту последнего AI-сообщения (regex-эвристики) и suggested prompts для пустого AI-чата.

### `getQuickReplies(aiMessage)` — L17
Принимает текст последнего сообщения AI, прогоняет через приоритезированные регэкспы (пауза → перегрузка → близкий забег → рост VDOT → «готово» → подтверждение → самочувствие → похвала → план → детали тренировки) и возвращает массив строк-ответов; дефолт — «Что на сегодня?» / «Покажи план на неделю». Чистая функция.

### `SUGGESTED_PROMPTS` — L75
Массив `{text, icon}` из 6 стартовых подсказок для пустого AI-чата.

## `src/screens/chat/chatTime.js` (36 строк)
Форматирование времени сообщений с учётом таймзоны пользователя.

### `formatChatTime(createdAt, userTimezone)` — L1
«ЧЧ:ММ» если сообщению < 24ч, иначе «ДД.ММ ЧЧ:ММ» (`ru-RU`, timeZone). Пустая строка без даты.

### `formatListTime(createdAt, userTimezone)` — L26
Компактное время для списка чатов: «ЧЧ:ММ» если тот же календарный день в TZ, иначе «ДД.ММ».

## `src/screens/ChatScreen.jsx` (1333 строки)
Экран чата, двухколоночный layout: AI-тренер (стриминг, тул-статусы, quick-replies), «От администрации», личные диалоги (фото/голос/эмодзи) и админ-режим ответов пользователям. Оркестрирует хуки из `./chat/*`.

### `getToolLabel(name)` — L57
Русская метка работающей AI-тулзы из `TOOL_LABELS` (L37), фолбэк «Работаю…».

### `ChatAiStatus({phase})` — L59
Индикатор статуса ответа AI: typing-дотсы при `connecting`, «Печатает…» при `streaming/pending`, спиннер + метка тулзы при `tool:<name>`, «Ошибка» при пустой фазе.

### `getMessageAttachment(msg)` — L91
Парсит `metadata` сообщения (JSON-строка или объект) и возвращает вложение `{kind: image|audio, file, ...}` либо null.

### `getMessageToolsUsed(msg)` — L101
Из `metadata.tools_used` возвращает непустой массив имён сработавших тулз либо null (для карточки `ToolResultCard`).

### `deriveChatKind(chat)` — L120
Тип чата по id: `ai | admin | admin_mode | dialog`.

### `ChatEntityAvatar({chat, size, avatarBase, withOnline})` — L129
Аватар сущности чата: «AI»-градиент (+онлайн-точка), иконка администрации/админ-режима, либо фото/инициалы пользователя (`getAvatarSrc`, `getInitials`).

### `dayKeyOf(createdAt, tz)` — L159
Ключ дня `en-CA` (YYYY-MM-DD) в заданной TZ — для дата-сепараторов.

### `formatDateSeparator(createdAt, tz)` — L164
«СЕГОДНЯ» / «ВЧЕРА» / «D MMMM» (uppercase, ru-RU) для разделителей по дням.

### `ChatScreen()` — L178 (default export)
Без пропсов. Сторы/хуки: `useAuthStore`, `usePlanStore` (plan/loadPlan для контекст-стрипа «сегодня по плану» в личных диалогах), `useWorkoutRefreshStore` (triggerRefresh при синхронных write-тулзах AI из `INSTANT_REFRESH_TOOLS` L115, с «засевом» истории), `useChatUnread` (total/by_type), `useIsTabActive('/chat')`, `useNavigate`, `ChatSSE` (subscribe → mark-read/перезагрузка нужного списка только при изменении снапшота непрочитанных), и кастомные: `useChatDirectories`, `useChatNavigation`, `useChatMessageLists`, `useChatSubmitHandlers`, `useVoiceRecorder`. API напрямую: `chatMarkRead`, `chatAdminMarkConversationRead`, `uploadChatMedia`, `get_chat_media` (URL). Ключевые состояния: `input/sending/streamPhase`, `recalcMessage/nextPlanMessage`, `chatAdminSending`, `isPinnedToBottom` (+ refs прилипания к низу: `shouldStickToBottomRef`, `forceScrollOnNextChangeRef`), `pendingImage/imageUploading/lightboxUrl`, `voiceUploading`, `markAllReadLoading`. Мемо: `todayWorkout` (план на сегодня через `getPlanDayForDate` + `WORKOUT_TYPE_LABEL`), `aiQuickReplies` (по последнему AI-сообщению). Эффекты: класс `chat-conversation-open` на body (прячет BottomNav на мобиле), сброс инпута/стрима при смене чата, поллинг AI-ответа (1.2с + каждые 3с при `aiPendingResponse`), mark-read при открытии AI/админ-чата, серия `useLayoutEffect` для автоскролла (скролл к `scrollToMessageId`, скролл вниз при смене чата/новых сообщениях/возврате на вкладку/закрытии мобильного списка), ResizeObserver для подскролла при изменении высоты. Рендер: sidebar (список чатов с аватарами/бейджами/временем; для админа — вкладки Личный|Администраторский и список пользователей; кнопка «Прочитать все»), main — четыре ветки: админ-режим (header + сообщения + composer), личный диалог (контекст-стрип, вложения фото/голос, mic/send-переключение), AI/админ-чат (CapabilitiesBanner, suggested prompts на пустом чате, proactive-метки, ToolResultCard, quick-replies-бар, статус стрима), placeholder «Выберите чат»; кнопка «К последним», лайтбокс фото.
Значимые хендлеры/фрагменты: `handleBeforeSend` (L329 — форс-скролл при отправке), `cleanupPendingAsync` (L389), `isNearBottom` (L406), `updateShouldStickToBottom` (L414), `scrollToBottom` (L420 — двойной rAF, force/прилипание), `handleJumpToLatest` (L446), `handleMarkAllRead` (L628), `insertEmoji` (L641), `chatMediaUrl` (L651), `onPickImageFile` (L657 — валидация image/8МБ + objectURL), `removePendingImage` (L669), `handleComposerSubmit` (L676 — upload вложения + `sendContent`), `handleStartVoice/handleCancelVoice/handleSendVoice` (L725/733/735), `renderAttachment` (L749 — `VoiceMessage` или img c лайтбоксом), JSX-фрагменты `composerAttach` (L697) и `composerRecordingBar` (L769).

## `src/screens/chat/useChatDirectories.js` (47 строк)
Хук-справочник списков для сайдбара чата: личные диалоги и (для админа) пользователи, писавшие администрации.

### `useChatDirectories(api, isAdmin)` — L3
Возвращает `{directDialogs, directDialogsLoading, chatUsers, chatUsersLoading, loadDirectDialogs, loadChatUsers}`. `loadDirectDialogs` (L9) — `api.chatGetDirectDialogs()`, автозапуск на маунте; `loadChatUsers` (L22) — `api.getAdminChatUsers()`, только для админа, вызывается извне. Ошибки гасятся пустыми массивами. `directDialogsLoading` снаружи не используется.

## `src/screens/chat/useChatMessageLists.js` (115 строк)
Хук состояний и загрузчиков трёх списков сообщений: основной (AI/админ-чат), личный диалог, админ-режим.

### `useChatMessageLists(api, selectedChat, loadDirectDialogs)` — L4
Возвращает messages/conversationId/aiPendingResponse/error/loading + сеттеры и три загрузчика. `loadMessages(options)` (L18): скип для диалогов/админ-режима; `api.chatGetMessages(chat, 50, 0)` с гонкой против 15с таймаута, защита от смены вкладки во время запроса (`selectedChatRef`), реверс списка, выставляет `conversationId` и `aiPendingResponse` (только для AI-вкладки, поддерживает оба формата `pending_ai_response`); silent-режим не трогает loading/error. `loadUserDialogMessages(targetUserId)` (L65): `chatGetDirectMessages(.., 100, 0)` + реверс + обновление списка диалогов. `loadChatAdminMessages(userId)` (L80): `chatAdminGetMessages(.., 100, 0)` + реверс. `setConversationId`/`setLoading` из возврата снаружи не используются.

## `src/screens/chat/useChatNavigation.js` (333 строки)
Хук навигации чата: выбранная вкладка, резолв контакта из `?contact=slug`/location.state, построение списка чатов, мобильная видимость списка, выбранный пользователь админ-режима.

### `useChatNavigation({api, chatUsers, chatUsersLoading, directDialogs, isAdmin, userTimezone})` — L7
Читает `location.state` (openAdminMode/selectedUserId/openAdminTab/openAITab/contactUserSlug/contactUser/messageId) и `?contact=` из URL. Состояния: `contactUser` (+loading), `adminSection` (personal|admin_mode), `selectedChat` (ленивые инициализаторы по state/URL, дефолт TAB_AI), `mobileListVisible`, `selectedChatUser`. Эффекты: резолв контакта по slug через `api.getUserBySlug` (L54), реакция на location.state по `location.key` (L88 — выставляет вкладку/секцию/мобильную видимость), фолбэк на TAB_AI при неудачном резолве контакта (L142), апгрейд TAB_USER_DIALOG → `dialog_<id>` (L149), автоподбор `selectedChatUser` из `chatUsers` по `selectedUserIdFromState` (L220). Производные: `userDialogChat` (виртуальная вкладка контакта, не дублирует существующий диалог), `directDialogChats` (из `directDialogs`, время через `formatListTime`, unreadCount), `personalChats` = системные + диалоги + контакт, `chats` (+ADMIN_CHAT для админа), `dialogUserId/contactUserForDialog/isUserDialog`, `currentChat` (memo). Возвращает также флаги `isAiChat/isAdminChat/isAdminMode/isUserDialog` и хендлеры.
Значимые хендлеры: `clearContactSearchParam` (L46), `handleSelectChat` (L248 — переключение вкладки, синхронизация `?contact=` для диалогов, сброс admin-state), `handleAdminSectionChange` (L282), `handleSelectChatUser` (L291), `handleBackToList` (L303).

## `src/screens/chat/useChatSubmitHandlers.js` (396 строк)
Хук отправки сообщений всех видов чатов и операций очистки/прочтения; ядро — `sendContent` со стримингом AI и фолбэками.

### `useChatSubmitHandlers({...~28 параметров})` — L6
Принимает api/user/идентификаторы выбранного чата/сеттеры состояний/refs (`streamAbortRef`, `isMountedRef`, `notificationTimersRef`)/загрузчики/`onBeforeSend`. Возвращает `{handleSubmit, sendContent, sendDirect, handleAdminChatSend, handleClearAiChat, handleClearAdminChat, handleClearDirectDialog, handleMarkAllRead}`.
- `sendContent(content, attachment)` (L53) — ядро: оптимистичное сообщение `temp-<ts>`; ветка TAB_ADMIN → `chatSendMessageToAdmin` (замена temp-id на серверный, откат при ошибке); ветка `dialog_*` → `chatSendMessageToUser` (запрет писать себе, обновление списка диалогов); AI с вложением → нестриминговый `chatSendMessage` (плейсхолдер `temp-ai-`, удаление при пустом ответе); AI текст → `chatSendMessageStream` с rAF-батчингом чанков, AbortController, колбэками `onFirstChunk/onToolExecuting` (копит `tools_used` в metadata) /`onPlanUpdated` (loadPlan стора)/`onPlanRecalculating`/`onPlanGeneratingNext` (баннеры на 8с), таймаут 180с; при пустом/упавшем стриме фолбэк на нестриминговый `chatSendMessage` (авто-рефреш токена/ретраи).
- `handleSubmit(event)` (L296) — submit формы: текст из инпута → `sendContent`; снаружи не используется (ChatScreen использует собственный `handleComposerSubmit`).
- `sendDirect(text)` (L303) — прямая отправка (quick-replies, suggested prompts).
- `handleAdminChatSend(event)` (L308) — отправка от имени админа выбранному пользователю (`chatAdminSendMessage`) + перезагрузка переписки.
- `handleClearAiChat` (L336) / `handleClearAdminChat` (L348) / `handleClearDirectDialog` (L359) — confirm + `chatClearAi/chatClearAdmin/chatClearDirectDialog`, очистка локального списка.
- `handleMarkAllRead(isAdmin)` (L372) — `chatMarkAllRead` (+`chatAdminMarkAllRead` для админа), обнуляет счётчики в `ChatSSE`.

## `src/screens/chat/useVoiceRecorder.js` (143 строки)
Хук записи голосовых через MediaRecorder: start/stop/cancel + таймер секунд.

### `pickMime()` — L17
Первый поддерживаемый MIME из `MIME_CANDIDATES` (webm/opus → webm → mp4 → ogg), пустая строка если ничего.

### `describeMicError(e)` — L25
Человекочитаемое русское описание ошибки getUserMedia по `e.name` (запрещён/не найден/занят/небезопасный контекст/прерван).

### `extForType(type)` — L46
Расширение файла по MIME (m4a/ogg/mp3/wav, дефолт webm).

### `useVoiceRecorder()` — L54
Возвращает `{recording, seconds, start, stop, cancel}`. `start()` (L76): проверки secure context/getUserMedia/MediaRecorder (бросает Error с русским текстом), запрашивает микрофон, создаёт MediaRecorder с выбранным MIME, копит чанки, запускает посекундный таймер. `stop()` (L123): Promise, резолвится в `onstop` объектом `{file: File('voice.<ext>'), duration (≥1с), type}` либо null при пустой записи. `cancel()` (L130): останавливает без результата. `cleanup` (L64) глушит треки/таймер; вызывается и при анмаунте.
