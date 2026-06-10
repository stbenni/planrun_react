# Мёртвый код: frontend (верифицировано)

Дата: 2026-06-10. Метод: reachability-анализ от `src/main.jsx` + адверсальная верификация каждого кандидата
(точный резолв import-путей по всем js/jsx/html, поиск динамических `import(...)`, `new Worker`/`new URL`,
строковых ключей, JSX-использований, `this.`-вызовов и `get().`-вызовов Zustand; index.html, vite.config.js,
capacitor.config.json, package.json, tailwind.config.js, public/ проверены). Упоминания в docs/, dist/,
android/ за живое использование не считались.

## 1. Мёртвые файлы (подтверждено, можно удалять)

Все 33 файла образуют замкнутый подграф: ни один не импортируется живым кодом, ссылки есть только друг на друга.
Шаблонных динамических импортов (`import(\`...\`)`) в src/ нет — единственный variable-free динамический импорт
(`modulePreloader.js` → UserProfileScreen) кандидатов не касается.

### Цепочка A: старый Dashboard (замена — `Dashboard/v3/DashboardV3.jsx`, см. `DashboardScreen.jsx:9`)

| Файл | Строк | Единственный потребитель | Как проверено |
|---|---|---|---|
| src/components/Dashboard/Dashboard.jsx | 799 | — (корень, 0 импортёров) | DashboardScreen.jsx:9 импортирует v3/DashboardV3 |
| src/components/Dashboard/AthleteMobileTabs.jsx | 74 | Dashboard.jsx:39 | резолв импортов |
| src/components/Dashboard/DashboardStatsWidget.jsx | 168 | Dashboard.jsx:26 | резолв импортов |
| src/components/Dashboard/DashboardWeekStrip.jsx | 244 | Dashboard.jsx:25 | резолв импортов |
| src/components/Calendar/WeekCalendarIcons.jsx | 12 | DashboardWeekStrip.jsx:7 | резолв импортов |
| src/components/Dashboard/PersonalRecordsWidget.jsx | 123 | Dashboard.jsx:41 | резолв импортов |
| src/components/Dashboard/RacePredictionWidget.jsx | 342 | Dashboard.jsx:34 | резолв импортов |
| src/components/Dashboard/TrainingLoadWidget.jsx | 602 | Dashboard.jsx:35 | в DashboardV3.jsx:12 — только устаревший комментарий |
| src/components/common/InfoTooltip.jsx | 130 | TrainingLoadWidget.jsx:11 | резолв импортов |
| src/components/Dashboard/TrendComparisonWidget.jsx | 162 | Dashboard.jsx:42 | резолв импортов |
| src/components/Dashboard/dashboardConfig.js | 16 | Dashboard.jsx:27, dashboardLayout.js:1 (оба мертвы) | резолв импортов |
| src/components/Dashboard/dashboardLayout.js | 108 | Dashboard.jsx:29 | резолв импортов |
| src/components/Dashboard/v3/DashStickyTabsV3.jsx | 90 | Dashboard.jsx:37 | в DashboardV3.jsx:7 — только комментарий |
| src/components/Dashboard/DashboardMetricIcons.jsx | 11 | DashboardStatsWidget.jsx:8, ProfileQuickMetricsWidget.jsx:9 (оба мертвы) | резолв импортов |
| src/components/Dashboard/ProfileQuickMetricsWidget.jsx | 144 | — (0 импортёров) | grep по имени: 0 вне файла |
| src/components/Dashboard/DailyBriefingHero.jsx | 81 | — (0 импортёров) | grep по имени: 0 вне файла |

Итого по цепочке: 16 файлов, 3106 строк.

### Цепочка B: старый Calendar (замена — `Calendar/v3/MonthViewV3` / `WeekViewV3`)

| Файл | Строк | Единственный потребитель | Как проверено |
|---|---|---|---|
| src/components/Calendar/Calendar.jsx | 81 | — (корень, 0 импортёров) | CalendarScreen использует v3 |
| src/components/Calendar/Week.jsx | 122 | Calendar.jsx:7 | резолв импортов |
| src/components/Calendar/Day.jsx | 99 | Week.jsx:7 | резолв импортов |
| src/components/Calendar/RouteMap.jsx | 112 | — (0 импортёров) | живые карты: Stats/MapboxRouteMap, Stats/LeafletRouteMap |

Итого по цепочке: 4 файла, 414 строк.

### Цепочка C: barrel Stats/index.js и легаси-графики (живой Stats = `Stats/v3/StatsV3` + statsV3Utils)

| Файл | Строк | Единственный потребитель | Как проверено |
|---|---|---|---|
| src/components/Stats/index.js | 29 | — (корень, 0 импортёров) | резолв в т.ч. directory-импортов `…/Stats` → index: 0 |
| src/components/Stats/ActivityHeatmap.jsx | 335 | index.js:9 | резолв импортов |
| src/components/Stats/DistanceChart.jsx | 166 | index.js:10 | резолв импортов |
| src/components/Stats/WeeklyProgressChart.jsx | 47 | index.js:11 | резолв импортов |
| src/components/Stats/HeartRateChart.jsx | 382 | index.js:12, WorkoutShareCard.jsx (мёртв) | резолв импортов |
| src/components/Stats/PaceChart.jsx | 502 | index.js:13 | резолв импортов |
| src/components/Stats/RecentWorkoutsList.jsx | 122 | index.js:17 | резолв импортов |
| src/components/Stats/RecentWorkoutIcons.jsx | 17 | RecentWorkoutsList.jsx:7 | резолв импортов |
| src/components/Stats/AchievementCard.jsx | 20 | index.js:18 | резолв импортов |
| src/components/Stats/WorkoutShareCard.jsx | 1192 | — (0 импортёров) | `getWorkoutShareCard`/`storeWorkoutShareCard` в ApiClient.js:1218,1222 и statsApi.js:66,70 — это методы API backend-шаринга, не компонент; актуальный шаринг = Share/ShareComposer |

Итого по цепочке: 10 файлов, 2812 строк.

### Цепочка D: chat-воркер (живой путь стриминга — `src/services/ChatSSE.js`, EventSource)

| Файл | Строк | Единственный потребитель | Как проверено |
|---|---|---|---|
| src/services/ChatStreamWorker.js | 62 | — (0 импортёров) | grep `ChatStreamWorker`/`chatStream` по src: 0 вне пары; `new Worker` в живом коде отсутствует |
| src/workers/chatStream.worker.js | 84 | ChatStreamWorker.js:29 (`new URL('../workers/chatStream.worker.js', import.meta.url)`) | единственная ссылка — из мёртвого файла; ChatSSE.js:74 создаёт `new EventSource(...)`, воркер не нужен |

Итого по цепочке: 2 файла, 146 строк.

### Одиночные

| Файл | Строк | Потребитель | Как проверено |
|---|---|---|---|
| src/stores/useWorkoutStore.js | 186 | — | grep `useWorkoutStore` по src + index.html: 0 вне файла (актуален useWorkoutRefreshStore) |

**Итого секция 1: 33 файла, 6664 строки.**

### Осиротевшие CSS (импортируются только мёртвыми файлами — удалить вместе с ними)

10 файлов, 3623 строки: `Dashboard/AthleteMobileTabs.css` (54), `Dashboard/DailyBriefingHero.css` (150),
`Dashboard/PersonalRecordsWidget.css` (151), `Dashboard/RacePredictionWidget.css` (632),
`Dashboard/TrainingLoadWidget.css` (505), `Dashboard/TrendComparisonWidget.css` (171),
`Dashboard/v3/DashStickyTabsV3.css` (61), `Calendar/RouteMap.css` (160),
`Calendar/WeekCalendar.css` (1598, только из мёртвого DashboardWeekStrip),
`common/InfoTooltip.css` (141).

ВНИМАНИЕ: `Dashboard/Dashboard.css` НЕ удалять — импортируется живыми `DashboardScreen.jsx` и
`UserProfileScreen.jsx`. `assets/css/calendar_v2.css` и `assets/css/short-desc.css` НЕ удалять —
импортируются живыми `CalendarScreen.jsx` / `DayModal.jsx`.

## 2. Мёртвые экспорты в живых файлах

Проверено: внешний grep по всему src/ + index.html (включая строковые ключи и `obj[name]` — substring-поиск
поймал бы и их), внутреннее использование (`this.`, `get().`, JSX, вызовы).

### 2a. Полностью мёртвые (18) — можно удалять символ целиком

| Символ | Файл:строка | Вердикт |
|---|---|---|
| ApiClient::getRecentWorkoutAnalyses | src/api/ApiClient.js:1156 | мёртв полностью (0 вызовов, в т.ч. this.) |
| isTelegramMobile | src/services/telegramMiniApp.js:58 | мёртв полностью |
| isTelegramDesktop | src/services/telegramMiniApp.js:64 | мёртв полностью |
| useAuthStore::setLocked | src/stores/useAuthStore.js:61 | мёртв полностью (единственное упоминание — комментарий :88) |
| useWorkoutRefreshStore::startDataPolling | src/stores/useWorkoutRefreshStore.js:181 | мёртв полностью (legacy-алиас startAutoRefresh, 0 вызовов) |
| useWorkoutRefreshStore::stopDataPolling | src/stores/useWorkoutRefreshStore.js:182 | мёртв полностью (legacy-алиас stopAutoRefresh, 0 вызовов) |
| getPlanWeekCategories | src/utils/calendarHelpers.js:427 | мёртв полностью |
| getFirstName | src/utils/displayName.js:23 | мёртв полностью |
| durationToSeconds | src/utils/durationMask.js:75 | мёртв полностью |
| normalizeValue | src/screens/settings/profileForm.js:3 | мёртв полностью |
| NavIconMail | src/components/common/BottomNavIcons.jsx:56 | мёртв полностью |
| NavIconProfile | src/components/common/BottomNavIcons.jsx:88 | мёртв полностью |
| FootprintsIcon | src/components/common/Icons.jsx:131 | мёртв полностью |
| PaperclipIcon | src/components/common/Icons.jsx:247 | мёртв полностью |
| CameraIcon | src/components/common/Icons.jsx:268 | мёртв полностью |
| PaletteIcon | src/components/common/Icons.jsx:271 | мёртв полностью |
| GlobeIcon | src/components/common/Icons.jsx:283 | мёртв полностью |
| PointerIcon | src/components/common/Icons.jsx:301 | мёртв полностью |

### 2b. Живы внутри файла, экспорт/публичность лишние (26) — код не удалять, можно убрать `export`

| Символ | Файл:строка | Где жив внутри |
|---|---|---|
| ApiClient::refreshAccessToken | src/api/ApiClient.js:407 | this.-вызовы :334, :617, :844 |
| CredentialBackupService::isBiometricRecoveryEnabled | src/services/CredentialBackupService.js:112 | this.-вызовы :98, :157 |
| requestHealthConnectPermissions | src/services/healthConnectSync.js:52 | вызовы :80, :114 |
| getTelegramPlatform | src/services/telegramMiniApp.js:49 | вызовы :59, :65 (из мёртвых isTelegramMobile/Desktop — после их удаления умрёт тоже) |
| TokenStorageService::getDeviceId | src/services/TokenStorageService.js:197 | this.-вызов :239 |
| TokenStorageService::saveDeviceId | src/services/TokenStorageService.js:214 | this.-вызов :242 |
| useAuthStore::setupBackgroundLock | src/stores/useAuthStore.js:225 | get().setupBackgroundLock :160, :203, :366 |
| usePlanStore::stopStatusPolling | src/stores/usePlanStore.js:125 | get().stopStatusPolling :526 |
| usePlanStore::applyQueuedPlanState | src/stores/usePlanStore.js:172 | get().applyQueuedPlanState :341 |
| useWorkoutRefreshStore::checkForUpdates | src/stores/useWorkoutRefreshStore.js:43 | get().checkForUpdates :96, :110, :118, :135, :156 |
| preloadScreenModules | src/utils/modulePreloader.js:25 | вызов из preloadScreenModulesDelayed :41 (та импортируется App.jsx:19) |
| downloadBlob | src/utils/shareImage.js:76 | вызовы :104, :133 |
| Toggle | src/screens/settings/v3/primitives.jsx:56 | JSX в ToggleRow :77 (внешние совпадения — только ToggleRow/onToggle*) |
| derivePhaseKey | src/components/Calendar/v3/calV3.js:28 | вызовы :383, :406 |
| phaseNameToKey | src/components/Calendar/v3/calV3.js:39 | вызовы :383, :406 |
| parseVolumeKm | src/components/Calendar/v3/calV3.js:51 | вызовы :380, :387 |
| parsePlanMetrics | src/components/Calendar/v3/calV3.js:58 | вызовы :240, :300 |
| getVirtualWeek | src/components/Calendar/v3/calV3.js:191 | вызов :211 |
| setsLabel | src/components/Calendar/v3/ExerciseListV3.jsx:3 | JSX-вызовы :23 |
| APPLE_EMOJI_BASE | src/components/common/emojiAssets.js:7 | appleEmojiImageURL :9 |
| getNextMonday | src/components/Onboarding/onboardingForm.js:9 | вызов :33 |
| roundRectPath | src/components/Share/shareTemplates.js:12 | вызовы :281, :286, :295, :306, :312 |
| projectRoute | src/components/Share/shareTemplates.js:23 | вызов :174 |
| matchesSport | src/components/Stats/statsV3Utils.js:8 | вызовы :149, :294 |
| vdotEstimate | src/components/Stats/statsV3Utils.js:269 | вызов :315 |
| SPORTS | src/components/Stats/v3/blocks.jsx:7 | JSX map :35 |

## 3. Опровергнутые кандидаты (живые)

Внешне живых среди 33 файлов и 44 экспортов не нашлось (0 опровергнуто). Ниже — кандидаты из отчётов
batch-агентов, опровергнутые в ходе их/этой проверки, плюс смежные «похожие на мёртвые» сущности:

| Символ/файл | Где реально используется |
|---|---|
| src/screens/SettingsScreen.jsx | роутинг AppTabsContent.jsx:60,184 + SettingsPanel:51 (контейнер для SettingsV3) |
| src/screens/TrainersScreen.jsx | живой, делегирует FindTrainerV3 |
| src/screens/StatsScreen.jsx | живой, хостит StatsV3 |
| src/services/ChatSSE.js | единственный живой путь стриминга чата (EventSource, :74); потребители: ChatScreen, useChatSubmitHandlers, useNotificationFeed, useChatUnread |
| LoginModal | LandingScreen:202, UserProfileScreen:498 |
| useHealthConnect | SettingsScreen:200 |
| GoalCountdownWidget, PlanGeneratingState, useDashboardData, dashboardDateUtils, useDashboardPullToRefresh | живые потребители в Dashboard/v3 (не входят в мёртвую цепочку A) |
| WorkoutDetailsModal, WorkoutSheet, WorkoutShareButton, MapboxRouteMap, LeafletRouteMap, CombinedWorkoutChart, statsV3Utils, StatsUtils | живая часть Stats/ — импортируются напрямую, мимо barrel index.js |
| AddTrainingModal, ResultModal, DayModal | живые (DayModal — через UserProfileScreen) |
| preloadScreenModulesDelayed, preloadAuthenticatedModules | App.jsx:19, вызовы :90-91 |
| chatConstants: реэкспорты MessageCircle, MailIcon, UsersIcon | useChatNavigation.js:3, ChatScreen.jsx:24 (мёртв только реэкспорт BotIcon — см. секцию 4) |
| Dashboard/Dashboard.css | живые импортёры DashboardScreen.jsx, UserProfileScreen.jsx — удалять нельзя |
| ApiClient.getWorkoutShareCard / storeWorkoutShareCard | живой API backend-шаринга (statsApi.js:66,70) — не путать с мёртвым компонентом WorkoutShareCard.jsx |

## 4. Прочее полу-мёртвое (из batch-отчётов 22–30, верифицировано)

| Что | Файл:строка | Детали |
|---|---|---|
| handleSubmit в return хука | src/screens/chat/useChatSubmitHandlers.js:296 (def), :387 (return) | ChatScreen деструктурирует только sendContent/sendDirect/handleAdminChatSend/handleClear*/handleMarkAllRead (ChatScreen.jsx:334-342); сам колбэк нигде не вызывается — мёртв |
| directDialogsLoading в return хука | src/screens/chat/useChatDirectories.js:5, :41 | ChatScreen.jsx:190-196 не берёт; state ведётся вхолостую |
| setConversationId, setLoading в return хука | src/screens/chat/useChatMessageLists.js:98, :102 | внутри хука живые (:45, :22 и др.), из return никто не берёт — убрать из return |
| Реэкспорт BotIcon | src/screens/chat/chatConstants.js:23 | никто не импортирует BotIcon из chatConstants (живые потребители берут из common/Icons) |
| handleStartTelegramLogin | src/screens/settings/useSettingsActions.js:296 (def), :357 (return) | единственный потребитель хука SettingsScreen его не использует — колбэк мёртв целиком |
| settingsPanelsRef + useSwipeableTabs | src/screens/SettingsScreen.jsx:143, :629-630 | ref создан и передан в useSwipeableTabs как containerRef, но НЕ прикреплён к DOM (`ref={settingsPanelsRef}` отсутствует) — свайп no-op, вестигий |
| Две disabled-кнопки «Скоро» | src/components/Coach/EventQuickReplySheet.jsx:155, :161 | `disabled title="Скоро"` — заглушки без обработчиков |
| Проп length у PinInput игнорируется | src/components/common/PinInput.jsx:17 (проп), :21 (`const len = 4`) | PinSetupModal.jsx:93 передаёт `length={4}` — без эффекта (сейчас совпадает с хардкодом, но проп фиктивный) |
| useWeeksProgress — не хук | src/components/Dashboard/v3/GoalSectionV3.jsx:178 | названа хуком, React-хуков не содержит (обычная функция с ранним return) |
| Устаревшие комментарии DashboardV3 | src/components/Dashboard/v3/DashboardV3.jsx:7, :12 | ссылаются на DashStickyTabsV3 и TrainingLoadWidget, которых в v3-дереве нет (оба — мёртвая цепочка A) |
| Избыточная ветка openAITab | src/screens/chat/useChatNavigation.js:37-38 | `if (openAITab === true) return TAB_AI;` перед безусловным `return TAB_AI` |
| Неиспользуемый проп api | src/components/Stats/RecentWorkoutsList.jsx | файл целиком мёртв (секция 1, цепочка C) |
| Нарушение rules-of-hooks | src/components/Stats/ActivityHeatmap.jsx | ранний return до useEffect; файл целиком мёртв (секция 1) |

## Сводка

- Мёртвых файлов: **33** (6664 строки JS/JSX), все подтверждены адверсально: 0 живых импортёров, 0 динамических/worker/строковых ссылок.
- Бонус: **10 осиротевших CSS** (3623 строки) — удалять вместе с цепочками; Dashboard.css, calendar_v2.css, short-desc.css не трогать.
- Мёртвых экспортов: **18 полностью мёртвых** символов + **26 «экспорт лишний, жив внутри»** (из 44 кандидатов).
- Опровергнуто (оказалось живым): **0 из 77 формальных кандидатов**; дополнительно зафиксированы 13 «похожих на мёртвые» живых сущностей из batch-отчётов.
- Полу-мёртвое (секция 4): **13 позиций** — мёртвые return-значения хуков, вестигий свайпа в SettingsScreen, фиктивный проп PinInput, заглушки «Скоро», устаревшие комментарии.
- Потенциал удаления: **~10 300 строк** (6664 JS/JSX + 3623 CSS) без изменения поведения.
