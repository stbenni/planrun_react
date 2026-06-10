# Аудит системы уведомлений PlanRun

Дата: 2026-06-09. Цель — свести разрозненные «оповещательные» подсистемы к одному стандарту.

## TL;DR

Доставка (push/web-push/telegram/email) уже унифицирована через `NotificationDispatcher`.
In-app часть и **чат** живут отдельно, плюс существуют **3 дублирующих UI-агрегатора** и **2 словаря событий**.
Фундамент для унификации уже есть: `PlanNotificationService::notify()` пишет in-app строку **И** дёргает диспетчер — нужно «дотянуть» чат до этого пути и схлопнуть UI.

---

## Текущая архитектура

### Бэкенд

| Компонент | Роль | Статус |
|---|---|---|
| `services/NotificationDispatcher.php` | Единый движок ДОСТАВКИ: `dispatchToUser(userId, event_key, title, body)` → push/web-push/telegram/email по настройкам + очередь с TZ-гейтом (`notification_deliveries`) | ✅ единый |
| `services/PlanNotificationService.php` | Де-факто единый ПРОИЗВОДИТЕЛЬ: `notify(userId, type, message, metadata)` → INSERT в `plan_notifications` + вызов `NotificationDispatcher` | ⚠️ есть, но назван «plan» и используется не всеми |
| `controllers/BaseController.php` | Хелперы `notifyAthleteIfCoach`, `notifyCoachesResultLogged` → через `PlanNotificationService` | ✅ |
| `services/ChatService.php` | Чат шлёт **только** `NotificationDispatcher::dispatchToUser` (event_key `chat.ai_message`/`chat.admin_message`/`chat.direct_message`). **НЕ** пишет `plan_notifications` | ❌ шов |
| `services/CoachEventsService.php` | Отдельный поток событий для тренерского дашборда («Поток») | ⚠️ отдельная логика |
| `services/NotificationSettingsService.php` | Настройки доставки: каталог `event_key` × каналы + preferences | ✅ |

**Таблицы:** `plan_notifications` (in-app store, misnamed), `notification_deliveries` (лог/очередь доставки), `chat_messages`/`conversations` (чат).

### Фронтенд — 3 перекрывающихся агрегатора

| UI | Источник | Где живёт |
|---|---|---|
| `NotificationBell` + `NotificationCenter` (хук `useNotificationFeed`) | `plan_notifications` + **поллинг** `chatGetMessages('ai'/'admin')` + клиентский `dismissed`-набор; refresh 60с | `Dashboard/v3/DashHeaderV3` |
| `Notifications.jsx` (414 стр, старый) | свои «ближайшие тренировки + admin + AI» через **ChatSSE** | `UserProfileScreen` |
| `ChatNotificationButton` + `useChatUnread` | бейдж непрочитанного чата (отдельный счётчик) | `common/TopHeader` |

Плюс `screens/settings/notificationSettings.js` — каталог `event_key` × каналы (4-й словарь, слабо связан с `plan_notifications.type`).

> Следствие: в зависимости от экрана разная точка входа — на `TopHeader` кнопка чата, на v3-дашборде «колокол», в профиле старая панель.

---

## Корень фрагментации

1. **Два словаря событий:** `plan_notifications.type` + metadata ⟷ `event_key`-каталог настроек.
2. **Чат не в сторе:** доставка идёт, in-app строки нет → клиентский поллинг + отдельный unread.
3. **Три модели «прочитано»:** `plan_notifications.read_at` ⟷ chat conversation read ⟷ клиентский `dismissed`-набор.
4. **Три UI + два real-time** (поллинг 60с vs SSE) дублируют агрегацию.
5. **Нейминг** `plan_notifications` / `PlanNotificationService` маскирует, что это общий store.

---

## Предлагаемый единый стандарт

1. **Один производитель** — `NotificationService::create(userId, event_key, title, body, meta)`: 1 строка в единый store + вызов `NotificationDispatcher`. **Чат шлёт через него же** (event_key `chat.*`, ref на conversation) → автоматически в фид + доставку.
2. **Один словарь** — `event_key`-каталог единственный; `type` → `event_key`.
3. **Одна модель read** — `read_at` на строке; открытие связанного контекста гасит привязанную нотификацию. Убрать клиентский `dismissed`-хак и chat-поллинг.
4. **Один фид-API** `get_notifications` — всё из одного стора; бейдж = `unread` из одного источника; чат-бейдж = фильтр `category=chat`.
5. **Один UI** — `NotificationBell`+`NotificationCenter` во всех хедерах; ретайр `Notifications.jsx`; `ChatNotificationButton` → бейдж из общего стора.
6. **Один real-time** — SSE пушит новые строки нотификаций.

Реализация — отдельным многофазным планом (бэк: producer/чат-роутинг + миграция `type→event_key`; фронт: схлопнуть 3 агрегатора в 1). Поведение-сохраняющий.
