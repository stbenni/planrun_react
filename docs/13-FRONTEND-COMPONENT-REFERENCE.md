# PlanRun - ручной справочник по frontend screens и UI-компонентам

Этот документ собран вручную по `src/components/*` и `src/screens/*`.

Он дополняет:

- `01-FRONTEND.md` - обзор архитектуры frontend-слоя;
- `10-FRONTEND-MODULE-REFERENCE.md` - сервисы, hooks, utils и экранные helper-модули.

Задача этого файла: описать не только список экранов, а реальную экранную модель приложения, UI-shell, крупные составные компоненты, модалки и то, как фронтенд держит состояние между вкладками.

## 1. Главная модель UI: приложение с "живыми" вкладками

### `src/components/AppLayout.jsx`

`AppLayout` - это каркас авторизованной зоны, который почти всегда присутствует на экране.

Что он реально держит:

- `TopHeader`;
- `Notifications`;
- `PlanGeneratingBanner`;
- `AppTabsContent`;
- `BottomNav`;
- `SpecializationModal` после входа, если профиль ещё не завершил onboarding.

Практически важные детали:

- layout запускает polling обновлений тренировок, если у пользователя уже есть `api`;
- добавляет CSS-состояние `chat-page-active`, чтобы оболочка могла визуально подстроиться под чат;
- не управляет содержимым экранов напрямую, а обеспечивает постоянную оболочку поверх них.

### `src/components/AppTabsContent.jsx`

Это один из самых важных UI-модулей проекта.

Что он делает:

- лениво импортирует главные экраны через собственный `useLazyModule`, без `Suspense`;
- прогревает основные модули сразу после монтирования;
- не размонтирует вкладки при переключении, а лишь скрывает их;
- для роли coach/admin умеет показывать вместо обычного dashboard экран `AthletesOverviewScreen`;
- на маршруте `/trainers/apply` подменяет стандартную вкладочную модель на `ApplyCoachForm`.

Практический смысл:

- состояние экранов сохраняется между переходами;
- у `CalendarScreen`, `StatsScreen`, `ChatScreen`, `SettingsScreen` не сбрасываются локальные state и scroll так агрессивно, как в обычном route-per-screen приложении;
- поэтому часть экранов дополнительно проверяет `useIsTabActive`, чтобы не делать лишние запросы, пока вкладка скрыта.

## 2. Публичные и auth-экраны

### `src/screens/LandingScreen.jsx`

- Публичный marketing-entrypoint.
- Держит hero/CTA, модальные `LoginModal` и `RegisterModal`, ветку "Стать тренером".
- Учитывает `visualViewport`, safe-area и iOS-особенности, чтобы hero-секция не ломалась на мобильных браузерах.
- Если пользователь уже авторизован, закрывает модалки и возвращает его в приложение.

### `src/screens/LoginScreen.jsx`

- Простой fallback-экран логина.
- Использует `useAuthStore.login`, а на native включает JWT-ветку.
- По смыслу проще `LoginForm`: экран нужен для отдельного route-сценария, а модалки/встраиваемые формы используются в публичной оболочке и профиле.

### `src/components/LoginForm.jsx` и `src/components/LoginModal.jsx`

- `LoginForm` - переиспользуемая форма входа.
- `LoginModal` - modal-wrapper над этой формой.
- Их задача не только логин, но и безопасное встраивание auth-flow в `LandingScreen` и `UserProfileScreen`, не вырывая пользователя из текущего контекста.

### `src/screens/RegisterScreen.jsx`

Это один из самых больших экранов фронтенда.

Что он реально делает:

- работает в двух режимах: минимальная регистрация и `specializationOnly`;
- собирает форму аккаунта, цели, бегового профиля и расширенных полей;
- запускает email verification code flow через `useVerificationCodeFlow`;
- умеет делать realtime `assessGoal()` для race/time-improvement целей;
- после завершения специализации может закрыться как модалка и вернуть пользователя в авторизованную часть.

Особенно важно:

- self-mode пропускает часть шагов;
- экран не просто сохраняет форму, а адаптирует её под goal type и training mode;
- именно этот экран отвечает за первую прикладную настройку будущего AI-плана.

### `src/components/RegisterModal.jsx` и `src/components/SpecializationModal.jsx`

- `RegisterModal` встраивает `RegisterScreen` в публичный flow.
- `SpecializationModal` запускает тот же экран в режиме `specializationOnly`, уже после логина.
- Это значит, что onboarding и post-login specialization используют один и тот же крупный экран, но с разной обвязкой.

### `src/screens/ForgotPasswordScreen.jsx`

- Точка входа в reset flow.
- Делегирует сетевую механику в `usePasswordResetRequest`.
- Показывает cooldown/retry UX и состояние успешной отправки письма.

### `src/screens/ResetPasswordScreen.jsx`

- Завершающий этап сброса пароля по token query param.
- Использует отдельный auth client, не требует авторизации пользователя.
- Учитывает retry-after и cooldown через `useRetryCooldown`.

## 3. Основные экраны авторизованной зоны

### `src/screens/DashboardScreen.jsx`

- Очень тонкий route-layer.
- Почти вся прикладная логика живёт в `components/Dashboard/Dashboard.jsx`.
- Экран только прокидывает `api`, `user`, `isTabActive`, route-state с сообщением о генерации плана и функцию навигации в календарь/другие вкладки.

### `src/components/Dashboard/Dashboard.jsx`

Это главный домашний экран приложения.

Что в нём реально собрано:

- today workout card;
- next workout card;
- week strip;
- quick metrics;
- stats widget;
- race prediction widget;
- empty/generating/manual-mode states;
- кастомизация раскладки виджетов.

Особенности реализации:

- layout хранится как набор строк и слотов, а не как жёсткий JSX-template;
- есть drag-and-drop customizer с merge-zone и insert-zone логикой;
- на mobile двойные колонки схлопываются в более простой layout;
- экран зависит от `useDashboardData` и `useDashboardPullToRefresh`, то есть сам по себе это ещё и orchestration-shell.

### `src/screens/CalendarScreen.jsx`

Это основной экран для работы с планом и фактическими тренировками.

Что он делает:

- держит `week/month` режим;
- умеет открывать plan day, result entry, manual add training и workout details;
- поддерживает coach/public view через `?athlete=slug`;
- синхронизируется с `usePlanStore`, `useWorkoutRefreshStore` и `usePreloadStore`;
- обслуживает сценарии `recalculate`, `next plan`, `clear plan`.

Особенно важные нюансы:

- экран сам вычисляет `viewContext`, `canEdit`, `canView`, `isOwner` на основе owner/coaching/public access;
- умеет открываться сразу на конкретной дате, если переход пришёл с dashboard;
- при скрытой вкладке старается не делать лишних запросов, но сохраняет состояние из-за mounted-tabs модели.

### `src/screens/StatsScreen.jsx`

- Аналитический экран с вкладками `overview`, `progress`, `achievements`.
- Параллельно грузит summary, workouts list, all results и plan.
- Для coach/admin поддерживает просмотр статистики спортсмена через `?athlete=slug`.
- Использует `WorkoutDetailsModal` как drill-down в конкретную тренировку.

Практически важные детали:

- stats-tab bar работает как свайпаемая мобильная панель;
- экран повторно загружает данные по сигналу из `useWorkoutRefreshStore`;
- часть вычислений остаётся на фронте через `processStatsData`, `processProgressData`, `processAchievementsData`.

### `src/screens/ChatScreen.jsx`

Это самый сложный с точки зрения режимов экран общения.

Что он объединяет:

- AI-чат;
- диалог "От администрации";
- direct dialogs;
- admin mode для ответа пользователям.

Особенности:

- экран разделён на локальные домены `useChatNavigation`, `useChatDirectories`, `useChatMessageLists`, `useChatSubmitHandlers`;
- работает с streaming AI-ответом;
- подписывается на `ChatSSE`, чтобы обновлять unread state без полного polling;
- умеет очищать AI-чат, direct dialog и помечать разные conversation-типы прочитанными.

### `src/screens/SettingsScreen.jsx`

Крупнейший экран пользовательских настроек.

Что он реально держит:

- профиль и цели;
- темы и визуальные предпочтения;
- PIN/биометрию;
- Strava/Huawei/Polar integrations;
- Telegram-linking;
- browser web push и историю доставок;
- coach pricing;
- список текущих тренеров;
- notification preferences и quiet hours.

Особенности:

- экран очень stateful и использует локальные helpers из `src/screens/settings/*`;
- часть действий сохраняется как форма, а часть запускается отдельными action-handler'ами;
- tab-navigation анимирована через pill + `ResizeObserver`;
- на native учитываются платформенные сценарии блокировки и re-auth.

### `src/screens/TrainersScreen.jsx`

- Роль-зависимый экран.
- Для `role=user` показывает placeholder и CTA "Стать тренером".
- Для `role=coach` или `admin` переключается в табы запросов/спортсменов/каталога.
- Через него проходят запросы coach-athlete связи, каталог тренеров и доступ к заявкам.

### `src/screens/AthletesOverviewScreen.jsx`

- Реальный home-screen тренера.
- Показывает список учеников, блок "Требуют внимания", сортировки и фильтр по группам.
- Содержит `AthleteCard` и встроенную `GroupsModal` для управления тренерскими группами.

Что важно:

- "требуют внимания" вычисляется из последней активности и weekly compliance;
- экран забирает и athlete-list, и pending requests, и group metadata;
- это не просто список, а управляющая панель coach-role.

### `src/screens/AdminScreen.jsx`

- Админский кабинет с вкладками пользователей, site settings, notification templates и coach applications.
- Поддерживает swipeable tabs и большое количество локального состояния.
- Содержит нормализацию шаблонов уведомлений и UI-обвязку вокруг CRUD админских action'ов.

### `src/screens/UserProfileScreen.jsx`

- Гибридный экран публичного профиля и role-aware просмотра пользователя.
- Сам загружает профиль по `username_slug`, access flags и coaches.
- Если privacy разрешает, подтягивает plan, stats, workouts list и отображает week strip, metrics, recent workouts и календарные модалки.

Практически важные детали:

- умеет работать и для owner, и для coach, и для полностью публичного визита по share-token;
- может открывать login/register модалки прямо с профиля;
- связывает публичный profile-view с теми же dashboard/stats/calendar widget'ами, что используются внутри приложения.

## 4. Общие компоненты оболочки и навигации

### `src/components/common/TopHeader.jsx`

- Верхняя навигация приложения.
- Адаптируется под desktop dropdown и mobile drawer.
- Синхронизирует аватар, если в user-state его ещё нет.
- Учитывает role-specific маршруты, admin item и различия logout-пути для web/native.

### `src/components/common/BottomNav.jsx`

- Нижняя вкладочная навигация.
- Отдельно держит набор табов для обычного пользователя и coach-role.
- Работает вместе с mounted-tabs моделью `AppTabsContent`.

### `src/components/common/Notifications.jsx`

- Глобальный агрегатор уведомлений в шапке.
- Объединяет upcoming workouts, AI/admin unread, plan notifications и dismissed state.
- Подписывается на `ChatSSE`, чтобы быстро обновлять unread chat counters.
- По route-type решает, куда вести пользователя: в чат, план, конкретную дату или админский диалог.

### `src/components/common/PlanGeneratingBanner.jsx`

- Показывает пользователю, что backend сейчас генерирует, пересчитывает или продлевает план.
- Важно тем, что связывает user-facing UI с состоянием `usePlanStore`.

### `src/components/common/LockScreen.jsx`

- Локальная блокировка приложения после background/resume.
- Работает только для native сценариев с PIN/биометрией.
- Умеет один раз автоматически вызвать биометрию, показать password fallback и перевести пользователя в повторный auth-flow.

### Другие общие UI-элементы

| Компонент | Роль |
|-----------|------|
| `Modal.jsx` | Базовая модалка для повторно используемых диалогов |
| `PinInput.jsx` | PIN keypad/input компонент |
| `PinSetupModal.jsx` | Модалка первичной настройки PIN |
| `LogoLoading.jsx` | Брендированный loading state |
| `SkeletonScreen.jsx` | Общие skeleton states для тяжёлых экранов |
| `PublicHeader.jsx` | Header публичной части приложения |
| `AppErrorBoundary.jsx` | Глобальный crash boundary для UI |
| `PageTransition.jsx` | Контейнер анимации переходов |
| `ChatNotificationButton.jsx` | Компактная точка входа в chat notifications |
| `Icons.jsx` и `BottomNavIcons.jsx` | Собственный набор SVG-иконок домена |

## 5. Календарная подсистема

### `src/components/Calendar/WeekCalendar.jsx`

- Главный недельный рендер плана.
- Умеет показывать и настоящую неделю плана, и "виртуальную" пустую неделю, если плана ещё нет.
- Поддерживает swipe-навигацию между неделями.
- Хранит week notes, выбранную дату и загруженные day details.

Важно:

- компонент нормализует day activities, сравнивает plan и факт через `calendarHelpers`;
- работает и в owner-context, и в coach/public context;
- умеет вызывать day modal, result modal, workout details и copy-week flow.

### `src/components/Calendar/DayModal.jsx`

- Детальный просмотр дня.
- Загружает `getDay()` и notes для конкретной даты.
- Позволяет редактировать/удалять plan day, копировать день, открывать add-training modal и workout details.
- Работает как объединённая точка для плана, упражнений, заметок и фактических тренировок.

### `src/components/Calendar/ResultModal.jsx`

- Модалка ввода фактического результата.
- Умеет несколько форматов:
  бег;
  интервалы;
  фартлек;
  ОФП;
  СБУ.
- Подтягивает план дня, распаковывает structured/unstructured exercises и может предзаполнять форму из description plan day.

### `src/components/Calendar/AddTrainingModal.jsx`

- Ручное добавление или редактирование тренировки в плане.
- Поддерживает run/OFP/SBU типы и более сложные формы для интервалов и фартлека.
- Используется и как editor planned session, и как manual add flow.

### Дополнительные календарные компоненты

| Компонент | Роль |
|-----------|------|
| `MonthlyCalendar.jsx` | Месячная сетка с визуальными индикаторами типов тренировок |
| `WorkoutCard.jsx` | Карточка запланированной или выполненной тренировки |
| `Week.jsx`, `Day.jsx`, `Calendar.jsx` | Более старый/классический renderer календаря и его ячейки |
| `RouteMap.jsx` | Отрисовка маршрута тренировки |
| `WeekCalendarIcons.jsx` | Набор иконок именно для недельного календаря |

## 6. Dashboard-подсистема

| Компонент | Реальная роль |
|-----------|----------------|
| `DashboardWeekStrip.jsx` | Горизонтальная неделя с типами дней и статусом выполнения |
| `DashboardStatsWidget.jsx` | Компактные агрегаты по объёму/активности |
| `ProfileQuickMetricsWidget.jsx` | Метрики для owner/profile view, завязанные на план и progress map |
| `RacePredictionWidget.jsx` | Прогнозы по дистанциям и pacing-zones на основе backend prediction |
| `dashboardConfig.js`, `dashboardLayout.js`, `dashboardDateUtils.js` | Конфиг виджетов, дефолтные layout'ы и date-helpers dashboard'а |

Ключевая идея dashboard-подсистемы:

- сам экран `Dashboard.jsx` отвечает за композицию;
- derived data и refresh logic вынесены в `useDashboardData` и `useDashboardPullToRefresh`;
- виджеты стараются оставаться относительно "глупыми" и получать уже подготовленные данные.

## 7. Stats-компоненты и детализация тренировок

| Компонент | Реальная роль |
|-----------|----------------|
| `WorkoutDetailsModal.jsx` | Самая глубокая карточка одной тренировки: laps, timeline, pace, heart rate, edit/delete actions |
| `RecentWorkoutsList.jsx` | Лента последних тренировок с lazy expand/collapse |
| `ActivityHeatmap.jsx` | Heatmap активности |
| `DistanceChart.jsx`, `WeeklyProgressChart.jsx`, `PaceChart.jsx`, `HeartRateChart.jsx` | Основные графики статистики |
| `AchievementCard.jsx` | Карточка достижения |
| `WorkoutShareCard.jsx` | Share-oriented представление одной тренировки |
| `RecentWorkoutIcons.jsx` | Иконки типов активности для stats-list |

Что важно про этот блок:

- stats-компоненты получают данные уже в обработанном виде от `StatsUtils`;
- `WorkoutDetailsModal` переиспользуется не только в `StatsScreen`, но и из календаря и профиля;
- поэтому modal умеет работать и с частично загруженными, и с расширенными данными тренировки.

## 8. Coach/admin формы и специализированные UI-потоки

### `src/components/Trainers/ApplyCoachForm.jsx`

- Пятишаговая анкета "Стать тренером".
- Держит специализацию, опыт, достижения, bio/philosophy, сертификаты, контакты и pricing.
- Не просто форма, а полноценный onboarding-flow для coach application.

### `src/screens/TrainersScreen.jsx`, `src/screens/AthletesOverviewScreen.jsx`, `src/screens/AdminScreen.jsx`

Эти экраны образуют отдельную управленческую ветку приложения:

- `TrainersScreen` - каталог и связи;
- `AthletesOverviewScreen` - coach dashboard;
- `AdminScreen` - системное управление пользователями и настройками.

Именно через них frontend связывается с coach/admin API, а не через обычный пользовательский dashboard.

## 9. Важные фронтенд-инварианты

### 1. Экран может быть скрыт, но не размонтирован

- Из-за `AppTabsContent` нельзя автоматически считать, что unmount означает уход со вкладки.
- Поэтому многие экраны используют `useIsTabActive`, а не полагаются только на жизненный цикл React-компонента.

### 2. Один и тот же UI должен работать в трёх контекстах

- owner view;
- coach/admin view чужого спортсмена;
- публичный profile/share view.

Это особенно заметно в `CalendarScreen`, `StatsScreen`, `UserProfileScreen`, `DayModal`, `WorkoutDetailsModal`.

### 3. Dashboard, calendar, stats и profile делят между собой одни и те же виджеты

- Виджеты и модалки фронта сознательно переиспользуются.
- Поэтому правка одного визуального блока может менять сразу несколько экранов.

### 4. AI и план связаны через UI сильнее, чем кажется

- chat screen может инициировать пересчёт плана;
- dashboard и banner отражают состояние очереди генерации;
- calendar и stats обновляются по `useWorkoutRefreshStore` и plan status polling.

### 5. SettingsScreen - это отдельный подприложение внутри приложения

- У него собственная tab-navigation, собственные helper-модули и несколько разных типов сохранения;
- правки настроек почти всегда требуют проверки и формы профиля, и уведомлений, и PIN/biometric flow, и integrations.

## 10. Как читать frontend дальше

Если идти последовательно и глубоко:

1. `01-FRONTEND.md` - обзор слоёв.
2. Этот документ - экранная модель и составные UI-домены.
3. `10-FRONTEND-MODULE-REFERENCE.md` - hooks/services/utils/helper-модули.
4. Потом уже конкретные исходники `src/screens/*` и `src/components/*`, если нужен построчный разбор.
