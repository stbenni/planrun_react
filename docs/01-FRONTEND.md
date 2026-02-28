# PlanRun — документация фронтенда

Полное описание файлов и функций React-приложения.

---

## Точки входа

### `src/main.jsx`
Точка входа приложения.

| Функция/Элемент | Описание |
|-----------------|----------|
| `initLogger()` | Инициализация логгера |
| `installGlobalErrorLogger()` | Глобальный перехват ошибок |
| `ReactDOM.createRoot().render()` | Рендер App в корневой элемент |
| Класс `native-app` | Добавляется на `document.documentElement` при Capacitor (Android/iOS) для мобильного вида |

---

### `src/App.jsx`
Главный компонент приложения, роутинг, охрана маршрутов.

| Функция/Компонент | Описание |
|-------------------|----------|
| `ScrollToTop` | Компонент: при смене pathname прокручивает страницу вверх |
| `App` | Главный компонент: инициализация, maintenance, роуты |
| `handleLogin(username, password, useJwt)` | Обработчик входа |
| `handleLogout()` | Обработчик выхода |
| `handleRegister(userData)` | Обработчик регистрации (обновляет user в store) |

**Маршруты:** `/landing`, `/register`, `/login`, `/forgot-password`, `/reset-password`, `/` (AppLayout), `/:username` (UserProfileScreen).

---

## API-клиент

### `src/api/ApiClient.js`
Универсальный API-клиент для веба и Capacitor.

**Классы:**
| Класс | Описание |
|-------|----------|
| `ApiError` | Ошибка API: `code`, `message`, `attempts_left` |
| `ApiClient` | Клиент для всех запросов к бэкенду |

**ApiClient — методы:**

| Метод | Описание |
|-------|----------|
| `constructor(baseUrl)` | Создание клиента, определение baseUrl |
| `setToken(token, refreshToken)` | Сохранение токенов (localStorage, BiometricService) |
| `getToken()` | Получение access token |
| `getRefreshToken()` | Получение refresh token |
| `refreshAccessToken()` | Обновление access token через refresh |
| `request(action, params, method)` | Базовый метод запросов (GET/POST) |
| `login(username, password, useJwt)` | Вход (сессии или JWT) |
| `loginWithJwt(username, password)` | Вход с JWT |
| `logout()` | Выход |
| `requestResetPassword(email)` | Запрос сброса пароля |
| `confirmResetPassword(token, newPassword)` | Подтверждение сброса пароля |
| `sendVerificationCode(email)` | Отправка кода верификации |
| `registerMinimal({...})` | Минимальная регистрация |
| `register(userData)` | Полная регистрация |
| `completeSpecialization(payload)` | Завершение специализации |
| `validateField(field, value)` | Валидация поля |
| `getCurrentUser()` | Текущий пользователь |
| `getUserBySlug(slug, token)` | Публичный профиль по slug |
| `getPlan(userId, viewContext)` | Загрузка плана |
| `savePlan(planData)` | Сохранение плана |
| `getDay(date, viewContext)` | Данные дня |
| `saveResult(data)` | Сохранение результата тренировки |
| `getResult(date, viewContext)` | Результат тренировки |
| `uploadWorkout(file, opts)` | Загрузка GPX/TCX |
| `getAllResults(viewContext)` | Все результаты |
| `reset(date)` | Сброс дня |
| `getStats(viewContext)` | Статистика |
| `getAllWorkoutsSummary(viewContext)` | Сводка тренировок |
| `getAllWorkoutsList(viewContext, limit)` | Список тренировок |
| `getIntegrationOAuthUrl(provider)` | URL для OAuth интеграции |
| `syncWorkouts(provider)` | Синхронизация тренировок |
| `getIntegrationsStatus()` | Статус интеграций |
| `unlinkIntegration(provider)` | Отвязка интеграции |
| `getStravaTokenError()` | Ошибка токена Strava |
| `getWorkoutTimeline(workoutId)` | Таймлайн тренировки |
| `runAdaptation()` | Запуск адаптации |
| `regeneratePlan()` | Регенерация плана |
| `recalculatePlan(reason)` | Пересчёт плана |
| `generateNextPlan(goals)` | Генерация следующего плана |
| `checkPlanStatus(userId)` | Статус генерации плана |
| `deleteWeek(weekNumber)` | Удаление недели |
| `addWeek(weekData)` | Добавление недели |
| `addTrainingDayByDate(data)` | Добавление дня по дате |
| `deleteTrainingDay(dayId)` | Удаление дня |
| `updateTrainingDay(dayId, data)` | Обновление дня |
| `getAdminUsers(params)` | Список пользователей (admin) |
| `getAdminUser(userId)` | Пользователь (admin) |
| `updateAdminUser(payload)` | Обновление пользователя (admin) |
| `deleteUser(payload)` | Удаление пользователя |
| `getAdminSettings()` | Настройки (admin) |
| `updateAdminSettings(payload)` | Обновление настроек (admin) |
| `getSiteSettings()` | Настройки сайта |
| `chatGetMessages(type, limit, offset)` | Сообщения чата |
| `chatSendMessage(content)` | Отправка сообщения |
| `chatSendMessageStream(content, onChunk, opts)` | Streaming ответа чата |
| `chatSendMessageToAdmin(content)` | Сообщение админу |
| `chatGetDirectDialogs()` | Диалоги с админом |
| `chatGetDirectMessages(targetUserId, limit, offset)` | Сообщения диалога |
| `chatSendMessageToUser(targetUserId, content)` | Сообщение пользователю |
| `chatMarkRead(conversationId)` | Отметить прочитанным |
| `chatClearAi()` | Очистить AI-чат |
| `chatMarkAllRead()` | Отметить всё прочитанным |
| `chatAdminMarkAllRead()` | Admin: отметить всё |
| `chatAdminSendMessage(userId, content)` | Admin: отправить сообщение |
| `getAdminChatUsers()` | Admin: пользователи чата |
| `chatAdminGetMessages(userId, limit, offset)` | Admin: сообщения |
| `chatAdminMarkConversationRead(userId)` | Admin: отметить диалог |
| `chatAddAIMessage(userId, content)` | Admin: добавить AI-сообщение |
| `chatAdminGetUnreadNotifications(limit)` | Admin: непрочитанные |
| `chatAdminBroadcast(content, userIds)` | Admin: рассылка |
| `getNotificationsDismissed()` | Отклонённые уведомления |
| `dismissNotification(notificationId)` | Отклонить уведомление |
| `getProfile()` | Профиль пользователя |
| `updateProfile(data)` | Обновление профиля |
| `uploadAvatar(file)` | Загрузка аватара |
| `removeAvatar()` | Удаление аватара |
| `updatePrivacy(data)` | Обновление приватности |
| `unlinkTelegram()` | Отвязка Telegram |
| `listExerciseLibrary(category)` | Библиотека упражнений |
| `addDayExercise(...)`, `updateDayExercise(...)`, `deleteDayExercise(...)`, `reorderDayExercises(...)` | CRUD упражнений дня |

---

## Stores (Zustand)

### `src/stores/useAuthStore.js`
Состояние авторизации.

| Функция/Свойство | Описание |
|------------------|----------|
| `user` | Текущий пользователь |
| `api` | Экземпляр ApiClient |
| `loading` | Загрузка при инициализации |
| `isAuthenticated` | Флаг авторизации |
| `drawerOpen` | Открыто ли боковое меню (мобильное) |
| `initialize()` | Инициализация: ApiClient, проверка сессии/JWT, биометрия |
| `login(username, password, useJwt)` | Вход |
| `logout(clearStoredCredentials)` | Выход |
| `pinLogin(pin)` | Вход по PIN |
| `biometricLogin()` | Биометрический вход |
| `updateUser(userData)` | Обновление user |
| `setDrawerOpen(open)` | Открыть/закрыть drawer |
| `checkBiometricAvailability()` | Доступность биометрии |
| `checkPinAvailability()` | Настроен ли PIN |

### `src/stores/usePlanStore.js`
Состояние плана тренировок.

### `src/stores/useWorkoutStore.js`
Состояние тренировок и результатов.

---

## Экраны (screens)

### `src/screens/LandingScreen.jsx`
Лендинг: кнопки «Войти», «Регистрация», hero-блок.

### `src/screens/LoginScreen.jsx`
Экран входа (редирект на landing с openLogin).

### `src/screens/RegisterScreen.jsx`
Регистрация (минимальная или полная).

### `src/screens/DashboardScreen.jsx`
Дашборд: сводка, недельная полоска, метрики.

### `src/screens/CalendarScreen.jsx`
Календарь: недельный и месячный вид.

### `src/screens/StatsScreen.jsx`
Статистика: графики, достижения, последние тренировки.

### `src/screens/ChatScreen.jsx`
Чат с AI-тренером (streaming).

### `src/screens/TrainersScreen.jsx`
Экран тренеров.

### `src/screens/SettingsScreen.jsx`
Настройки: профиль, тренировки, конфиденциальность, интеграции, PIN.

### `src/screens/UserProfileScreen.jsx`
Публичный профиль `/:username`.

### `src/screens/ForgotPasswordScreen.jsx`
Запрос сброса пароля.

### `src/screens/ResetPasswordScreen.jsx`
Подтверждение сброса пароля по токену.

---

## Компоненты

### `src/components/AppLayout.jsx`
Layout авторизованной зоны: TopHeader, Notifications, PageTransition, AppTabsContent, BottomNav.

| Компонент | Описание |
|-----------|----------|
| `AppLayout` | Обёртка с хедером, уведомлениями, контентом, нижней навигацией |

### `src/components/AppTabsContent.jsx`
Переключение контента по pathname (все экраны смонтированы).

| Функция | Описание |
|---------|----------|
| `isActive(path)` | Проверка активного маршрута |
| `AppTabsContent` | Рендер DashboardScreen, CalendarScreen, StatsScreen, ChatScreen, TrainersScreen, SettingsScreen, AdminScreen по pathname |

### `src/components/common/TopHeader.jsx`
Хедер: лого, навигация, чат, аватар. На мобильном — drawer.

### `src/components/common/BottomNav.jsx`
Нижняя навигация (4 вкладки): Дэшборд, Календарь, Статистика, Тренеры.

### `src/components/common/BottomNavIcons.jsx`
Иконки для BottomNav.

### `src/components/common/PublicHeader.jsx`
Публичный хедер (лендинг, профиль).

### `src/components/common/Modal.jsx`
Базовый модальный компонент.

### `src/components/common/Notifications.jsx`
Уведомления (чат, администрация).

### `src/components/common/ChatNotificationButton.jsx`
Кнопка уведомлений чата.

### `src/components/common/SkeletonScreen.jsx`
Скелетон загрузки.

### `src/components/common/PageTransition.jsx`
Анимация перехода между страницами.

### `src/components/common/Icons.jsx`
Пул иконок (lucide-react + кастомные): RunningIcon, ActivityTypeIcon, DistanceIcon и др.

### `src/components/common/PinInput.jsx`
Поле ввода PIN-кода.

### `src/components/common/PinSetupModal.jsx`
Модалка настройки PIN.

### `src/components/Calendar/WeekCalendar.jsx`
Недельный календарь.

### `src/components/Calendar/MonthlyCalendar.jsx`
Месячный календарь.

### `src/components/Calendar/Week.jsx`
Неделя в календаре.

### `src/components/Calendar/Day.jsx`
День в календаре.

### `src/components/Calendar/DayModal.jsx`
Модалка дня: план, результаты, кнопки.

### `src/components/Calendar/WorkoutCard.jsx`
Карточка тренировки.

### `src/components/Calendar/AddTrainingModal.jsx`
Модалка добавления/редактирования тренировки.

| Функция | Описание |
|---------|----------|
| `parseTime`, `formatTime` | Парсинг/форматирование времени |
| `parsePace`, `formatPace` | Парсинг/форматирование темпа |
| `maskTimeInput`, `maskPaceInput` | Маски ввода |
| `selectCategory`, `backToCategory` | Выбор категории (бег/ОФП/СБУ) |
| `toggleExercise`, `addCustomExercise`, `removeCustomExercise`, `updateCustomExercise` | Управление упражнениями |
| `addFartlekSegment`, `removeFartlekSegment`, `updateFartlekSegment` | Сегменты фартлека |
| `handleSubmit` | Отправка формы |
| `AddTrainingModal` | Главный компонент |

### `src/components/Calendar/ResultModal.jsx`
Модалка записи результата тренировки.

### `src/components/Calendar/RouteMap.jsx`
Карта маршрута (GPX).

### `src/components/Calendar/WeekCalendarIcons.jsx`
Иконки для недельного календаря.

### `src/components/Dashboard/Dashboard.jsx`
Главный вид дашборда.

### `src/components/Dashboard/DashboardWeekStrip.jsx`
Полоска недели.

### `src/components/Dashboard/DashboardStatsWidget.jsx`
Виджет статистики.

### `src/components/Dashboard/DashboardMetricIcons.jsx`
Иконки метрик.

### `src/components/Dashboard/ProfileQuickMetricsWidget.jsx`
Быстрые метрики профиля.

### `src/components/Stats/HeartRateChart.jsx`
График пульса.

### `src/components/Stats/PaceChart.jsx`
График темпа.

### `src/components/Stats/AchievementCard.jsx`
Карточка достижения.

### `src/components/Stats/RecentWorkoutsList.jsx`
Список последних тренировок.

### `src/components/Stats/RecentWorkoutIcons.jsx`
Иконки типов тренировок.

### `src/components/Stats/WorkoutDetailsModal.jsx`
Модалка деталей тренировки.

### `src/components/Stats/WorkoutShareCard.jsx`
Карточка для шаринга тренировки.

### `src/components/Stats/StatsUtils.js`
Утилиты статистики.

### `src/components/LoginForm.jsx`
Форма входа.

### `src/components/RegisterModal.jsx`
Модалка регистрации.

### `src/components/SpecializationModal.jsx`
Модалка специализации (онбординг).

---

## Сервисы

### `src/services/BiometricService.js`
Биометрическая аутентификация (Capacitor).

| Функция | Описание |
|---------|----------|
| `checkAvailability()` | Доступность биометрии |
| `authenticateAndGetTokens(prompt)` | Аутентификация и получение токенов |
| `saveTokens(accessToken, refreshToken)` | Сохранение токенов |
| `getTokens()` | Получение токенов |
| `clearTokens()` | Очистка токенов |
| `isBiometricEnabled()` | Включена ли биометрия |

### `src/services/PinAuthService.js`
PIN-код приложения.

| Функция | Описание |
|---------|----------|
| `setPin(pin)` | Установка PIN |
| `verifyPin(pin)` | Проверка PIN |
| `verifyAndGetTokens(pin)` | Проверка и получение токенов |
| `setPinAndSaveTokens(pin, accessToken, refreshToken)` | Сохранение токенов с PIN |
| `isPinEnabled()` | Настроен ли PIN |
| `clearPin()` | Удаление PIN |

### `src/services/ChatStreamWorker.js`
Обёртка над Web Worker для чата.

### `src/services/ChatSSE.js`
SSE-клиент для чата (если используется).

---

## Хуки

### `src/hooks/useIsTabActive.js`
Определение активности вкладки (visibility).

### `src/hooks/useMediaQuery.js`
Медиа-запросы (breakpoints).

### `src/hooks/useChatUnread.js`
Непрочитанные сообщения чата.

---

## Утилиты

### `src/utils/logger.js`
Логгер: `initLogger`, `installGlobalErrorLogger`, `logger`.

### `src/utils/avatarUrl.js`
Формирование URL аватара.

### `src/utils/calendarHelpers.js`
Вспомогательные функции календаря.

### `src/utils/modulePreloader.js`
Предзагрузка модулей: `preloadAllModulesImmediate`, `preloadScreenModulesDelayed`.

---

## Workers

### `src/workers/chatStream.worker.js`
Web Worker для обработки стрима чата.
