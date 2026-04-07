# AI-тренер PlanRun — Полное описание системы

Документ описывает полный цикл построения AI-тренера PlanRun, реализованный по трём последовательным планам:

1. **Безотказный AI-тренер** (план: AI Coach System Upgrade) — рефакторинг монолита ChatService, 8 новых аналитических tools, проактивная система, автоматическая память, GoalProgressService, UX чата
2. **Глубокая интеграция LLM с PlanRun** (план: LLM Deep PlanRun Integration) — расширение tools с детальными контрактами, обогащение контекста (фазы макроцикла, зоны темпа, тренерские знания в промпте), дополнительные frontend-улучшения (stop button, send icon, thumbs feedback)
3. **Переход на Qwen 3-14B** — замена Ministral на более сильную модель для function calling и русского языка
4. **Глубокая интеграция AI в тренировочный процесс** (план: Deep AI Training Integration) — качество данных в tools, автоматические AI-реакции, AI на всех экранах приложения, персонализация стиля тренера, комплексное тестирование

---

## Оглавление

- [Этап 1: Безотказный AI-тренер](#этап-1-безотказный-ai-тренер)
- [Этап 1a: Расширение LLM-интеграции](#этап-1a-расширение-llm-интеграции)
- [Этап 2: Переход на Qwen 3-14B](#этап-2-переход-на-qwen-3-14b)
- [Этап 3: Глубокая интеграция AI в тренировочный процесс](#этап-3-глубокая-интеграция-ai-в-тренировочный-процесс)
- [Итоговая архитектура](#итоговая-архитектура)
- [Новые таблицы БД](#новые-таблицы-бд)
- [Все созданные файлы](#все-созданные-файлы)
- [Cron-задачи](#cron-задачи)
- [Переменные окружения](#переменные-окружения)

---

## Этап 1: Безотказный AI-тренер

**План:** AI Coach System Upgrade
**Цель:** Превращение монолитного AI-чата в модульную, надёжную систему тренерского сопровождения.

### 1.1 Рефакторинг ChatService (монолит → модули)

**Проблема:** `ChatService.php` — 4600+ строк, один файл отвечал за всё: tools, промпты, подтверждения, стриминг, парсинг.

**Решение:** Разбит на 6 модулей:

| Модуль | Файл | Ответственность |
|--------|------|-----------------|
| Оркестратор | `ChatService.php` | streamResponse, sendMessage, координация |
| Tools | `ChatToolRegistry.php` | Определение и выполнение всех 26 tools |
| Промпты | `ChatPromptBuilder.php` | System prompt, token budgeting, нормализация |
| Подтверждения | `ChatConfirmationHandler.php` | Write-gate, pending actions из metadata |
| Стриминг | `ChatStreamHandler.php` | NDJSON stream, фильтрация reasoning-блоков |
| Парсер | `ChatActionParser.php` | Санитизация ответов, ACTION-блоки |

### 1.2 Оптимизация промпта для 16K контекста

- Системный промпт сжат с ~1800 до ~800 слов (без потери смысла)
- Динамические секции: блоки «ЗАМЕНА НА ЗАБЕГ» и «ДОБАВЛЕНИЕ ТРЕНИРОВКИ» включаются только при соответствующем intent
- Бюджетирование токенов: `estimateTokens()` (~3.2 символа/токен для кириллицы), автоматическая обрезка истории при переполнении
- Отдельный сжатый промпт `buildToolResolutionMessages()` для tool-resolution раундов

### 1.3 Исправление критических проблем

- `get_date` добавлен в tools (ранее был только handler, но не определение)
- Structured pending confirmation: write-операции хранят `pending_action` (tool_name + args) в `chat_messages.metadata` вместо regex-парсинга
- `tryExecutePendingAction()` — надёжный механизм подтверждения через metadata
- `isProposal()` — различение AI-предложений от подтверждений уже выполненных действий
- Массив `$blockedActions[]` вместо singleton — корректная обработка нескольких write-операций в одном раунде
- Fix `sender_type === 'ai'` (было `'assistant'`) в ChatConfirmationHandler
- Fix `ChatRepository::getMessagesAscending` — добавлена колонка `metadata` в SELECT

### 1.4 Единая система действий

- `<!-- ACTION -->` блоки заменены на native tool calls
- Единый `ChatActionParser` для обработки оставшихся текстовых fallback
- `WRITE_TOOLS` и `ACTION_TOOLS` синхронизированы со всеми 26 tools

### 1.5 Новые tools для глубокого анализа (8 штук)

До этапа было 17 CRUD-tools. Добавлены **8 аналитических**:

| Tool | Назначение | Ключевые данные |
|------|-----------|-----------------|
| `analyze_workout` | Детальный разбор тренировки | laps, HR-зоны, pace splits по км, plan comparison, effort_assessment |
| `get_training_trends` | Тренды за N недель | weekly_data[], trends (improving/stable/declining), patterns (plateau, overreaching, cardiac_drift, volume_spike), vdot_history |
| `compare_periods` | Сравнение двух периодов | дельта по км, темпу, HR, best pace, compliance, текстовая оценка |
| `get_weekly_review` | Еженедельный анализ план vs факт | plan_vs_actual по дням, compliance %, ключевые тренировки, load trend, recommendations |
| `get_goal_progress` | Прогресс к цели | VDOT, predicted time, gap to target, milestones, trajectory (on_track/ahead/behind) |
| `get_race_strategy` | Стратегия на забег | pacing plan (start/cruise/finish), Daniels zones (E/M/T/I/R), nutrition, warmup, taper status |
| `explain_plan_logic` | Объяснение логики плана | фаза макроцикла, зачем этот тип, volume rationale, scope: day/week/phase |
| `report_health_issue` | Протокол травмы/болезни | assessment, return-to-run protocol (4 фазы), plan impact, suggest_recalculate |

**Итого после этапа: 26 tools** (18 исходных + 8 новых).

### 1.5a Исправление activity_type в log_workout

Tool `log_workout` ранее всегда записывал `activity_type_id = 1` (бег). Добавлен параметр `activity_type` (enum: running, walking, cycling, swimming, other, sbu) с маппингом на `activity_type_id`.

### 1.6 Умная память (ChatMemoryManager)

- Авто-извлечение фактов после диалогов через LLM-вызов
- Категории: injuries, preferences, personal_records, emotional_patterns, goals_timeline, important_events
- Структурированные факты с `expires_at` и `is_active`
- Включаются в контекст по релевантности

### 1.7 Проактивная система (ProactiveCoachService)

Cron-скрипт `proactive_coach.php` (каждые 15 минут) детектирует события:

| Событие | Триггер | AI-реакция |
|---------|---------|------------|
| Пропуск тренировки | Вечер, тренировка не отмечена | «Как прошло?» |
| Отличный результат | rating=5 или PR | Конкретная похвала |
| Длительная пауза | >3 дня без тренировок | «Давно не виделись!» |
| Приближение забега | 2 нед / 1 нед / 3 дня | Стратегия/настрой |
| ACWR опасный | >1.5 | «Рекомендую отдых» |
| Серия выполнения | 7+ дней подряд | «Отличная дисциплина!» |
| Новый PR | Рекорд дистанции/темпа | «Новый рекорд!» |

Throttling: не больше 1 проактивного сообщения в день на пользователя.

### 1.8 GoalProgressService — отслеживание прогресса к цели

- Еженедельные снимки: VDOT, weekly_km, compliance, ACWR, predicted race time
- Milestone detection: VDOT jump, volume record, consistency streak, goal achievable
- Cron: `goal_progress_snapshot.php` (понедельник 6:00)
- Данные через tool `get_goal_progress()` + ProactiveCoachService

### 1.9 Frontend UX чата

- **Markdown-рендеринг** — `react-markdown` (lazy-loaded) + `remark-gfm` для таблиц, списков, bold/italic в AI-сообщениях
- **Кнопка «Стоп генерации»** — `AbortController` для прерывания текущего streaming-запроса; уже полученный текст сохраняется как ответ
- **Input разблокирован при стриминге** — можно набирать текст во время ответа AI; отправка после завершения стрима
- **Lucide Send иконка** — `SendIcon` из `Icons.jsx` вместо текстового символа отправки
- **Thumbs up/down feedback** — кнопки под AI-сообщениями (ThumbsUpIcon / ThumbsDownIcon); feedback сохраняется в localStorage (`planrun_chat_feedback`)
- **Tool execution indicators** — при вызове tool показывается статус: «Загружаю план...», «Анализирую тренировку...» (NDJSON event `tool_executing`, маппинг в `getToolLabel()`)
- **Proactive message styling** — проактивные сообщения с бейджем по типу: «Анализ тренировки», «План на сегодня», «Итоги недели»
- **Contextual quick replies** — data-driven из `chatQuickReplies.js`; после analyze_workout: «Сравни с прошлой неделей»; после weekly_review: «Что на следующей неделе?»; после report_health_issue: «Пересчитай план»
- **Chat search context** — `ChatPromptBuilder::appendChatSearchSnippet()` + `CHAT_SEARCH_HISTORY=1` — поиск по истории чата для подстановки релевантных фрагментов в контекст LLM

---

## Этап 1a: Расширение LLM-интеграции

**План:** Глубокая интеграция LLM с PlanRun (LLM Deep PlanRun Integration)
**Цель:** Расширить tools детальными контрактами, обогатить контекст тренерскими знаниями, улучшить frontend чата.

Этот план развивал и дополнял Этап 1. Основные добавления:

### Критические исправления (техдолг)

- **Write-gate в non-streaming path** — `callLlm()` теперь проверяет WRITE_TOOLS так же как streaming path
- **Синхронизация tool list** — `buildToolResolutionMessages` автоматически генерирует список tools из ChatToolRegistry
- **activity_type в log_workout** — поддержка running, walking, cycling, swimming, other, sbu
- **Structured pending confirmation** — хранение `pending_action` в `chat_messages.metadata` вместо regex

### Детальные контракты tools

Для каждого из 8 аналитических tools определены:
- Полные параметры с типами и default-значениями
- Структура возвращаемого JSON (все подполя)
- Источники данных (какие таблицы и сервисы используются)
- Сценарии использования (какие вопросы пользователя активируют tool)

Примеры обогащённых контрактов:
- `analyze_workout` — добавлены `effort_assessment` (cardiac drift), `pace_analysis.consistency_coefficient`
- `get_training_trends` — `patterns[]` с детекцией plateau, overreaching, cardiac_drift, volume_spike
- `get_race_strategy` — `taper_status`, `nutrition_plan` (гели/вода по км), `warmup` по дистанции

### Обогащение контекста и промпта

В `ChatContextBuilder::buildContextForUser()` добавлены:
- Следующая неделя плана (summary: типы тренировок, общий объём)
- Текущая фаза макроцикла (base/build/peak/taper + номер недели)
- Тренировочные зоны темпа (E/M/T/I/R из TrainingStateBuilder)

В system prompt добавлены тренерские знания:
- Периодизация: что означает каждая фаза
- Ключевые принципы: правило 10%, 80/20, суперкомпенсация
- Типы тренировок: что развивает каждый тип
- Return-to-run протоколы при травмах
- Питание на забеге: углеводная загрузка, гели, гидратация

Инструкции по маршрутизации tools:
- «как прошла тренировка?» → `analyze_workout`
- «как я прогрессирую?» → `get_goal_progress` или `get_training_trends`
- «сравни январь и март» → `compare_periods`
- «как прошла неделя?» → `get_weekly_review`
- «почему сегодня интервалы?» → `explain_plan_logic`
- «как бежать марафон?» → `get_race_strategy`
- «я заболел» / «болит колено» → `report_health_issue`

### Frontend UX (дополнения к Этапу 1)

- **Кнопка «Стоп генерации»** — `AbortController` + `streamAbortRef.current?.abort()`, сохранение частичного ответа
- **Lucide SendIcon** — замена текстового символа на `SendIcon` из `Icons.jsx`
- **Thumbs up/down** — `ThumbsUpIcon` / `ThumbsDownIcon` под AI-сообщениями, feedback в localStorage
- **Input разблокирован при стриминге** — можно набирать текст во время генерации

---

## Этап 2: Переход на Qwen 3-14B

**Дата:** 29 марта 2026
**Причина:** Ministral (предыдущая модель) давала неточные ответы — путала данные тренировок, выдумывала цифры, плохо работала с function calling.

### Что изменилось

| Параметр | Было | Стало |
|----------|------|-------|
| Модель | Ministral (via llama-server) | **Qwen3-14B** (via llama-server) |
| Сервер | llama-server :8081 | llama-server :8081 (тот же) |
| API | OpenAI-compatible | OpenAI-compatible (тот же) |
| .env | `LLM_CHAT_MODEL=ministral` | `LLM_CHAT_MODEL=qwen3-14b` |

### Почему Qwen 3

- **Function calling** — нативная поддержка tool use, значительно лучше чем Ministral
- **Русский язык** — качество генерации на русском сопоставимо с GPT-4
- **14B параметров** — достаточно для reasoning при 16K контексте
- **Совместимость** — OpenAI-compatible API, никаких изменений в коде бэкенда
- **Спортивные знания** — хорошая база знаний по бегу, физиологии, периодизации

### Что НЕ менялось

- Весь бэкенд (ChatService, tools, промпты) остался прежним
- Фронтенд без изменений
- API-эндпоинты те же
- Формат streaming (NDJSON) тот же

Создан git-бэкап `v1.9` перед переходом.

---

## Этап 3: Глубокая интеграция AI в тренировочный процесс

**План:** Deep AI Training Integration
**Цель:** Вывести AI за пределы чата на все экраны приложения, улучшить качество данных, добавить автоматические реакции и персонализацию.

### Фаза 1: Качество данных в tools

#### 1.1 Fix avg_cadence для импортированных тренировок

**Файл:** `ChatContextBuilder.php` → `getDayDetails`

Каденс теперь вычисляется из `workout_timeline` вместо принудительного NULL:

```sql
(SELECT ROUND(AVG(wt.cadence))
 FROM workout_timeline wt
 WHERE wt.workout_id = w.id AND wt.cadence IS NOT NULL AND wt.cadence > 0
) AS avg_cadence
```

#### 1.2 Реальные pace splits по км

**Файл:** `ChatToolRegistry.php` → `analyzePaceSplits`

Полностью переписан. Нарезает timeline на отрезки по 1 км, возвращает:

```json
{
  "splits": [{"km": 1, "pace": "4:52", "hr": 155}, ...],
  "split_type": "negative_split",
  "fastest": {"km": 9, "pace": "4:38"},
  "slowest": {"km": 1, "pace": "4:52"}
}
```

#### 1.3 Обогащённый get_weekly_review

Добавлен блок `quality`: `week_trimp`, `avg_hr`, `best_pace`, `hr_drift_bpm` (аэробная форма), `zone_distribution` (Z1–Z5%).

#### 1.4 Источник тренировки

Поле `source` (strava/polar/garmin/coros/manual) в ответ `analyze_workout` — AI может сказать «по данным Strava...».

---

### Фаза 2: Автоматические AI-реакции

#### 2.1 Авто-анализ после синхронизации

`WorkoutService::importWorkouts` → `ProactiveCoachService::postWorkoutAnalysis`

При импорте тренировки автоматически вызывается `analyze_workout`, генерируется LLM-анализ, сохраняется как proactive message с metadata `proactive_type: post_workout_analysis`.

#### 2.2 Ежедневный брифинг

Cron `daily_briefing.php` (7:00 каждый день) — для пользователей с запланированной тренировкой генерирует краткий совет: тип, дистанция, темп, ACWR-статус.

#### 2.3 Еженедельный дайджест

Cron `weekly_digest.php` (воскресенье 20:00) — итоги недели через `get_weekly_review` + `get_goal_progress`, LLM формирует итоговое сообщение.

#### LLM-вызовы

`callLlmSimple(string $prompt): string` — не-streaming POST к `LLM_CHAT_BASE_URL`, max_tokens 300, temperature 0.7.

---

### Фаза 3: AI на всех экранах

#### 3.1 Кнопка «Спросить тренера» в DayModal

`DayModal.jsx` — кнопка с `BotIcon`. Навигация на `/chat?context=workout&date=...` или `/chat?context=day&date=...`. ChatScreen автоматически подставляет вопрос.

#### 3.2 Вкладка «AI-анализ» в WorkoutDetailsModal

- Новый endpoint `analyze_workout_ai` (GET, кеш 24ч)
- Вызывает `ChatToolRegistry::executeTool('analyze_workout')` + LLM-нарратив
- Вкладка «AI-анализ» с narrative, pace splits, HR zones

#### 3.3 Виджет «Совет тренера» на Dashboard

`CoachTipWidget.jsx` — показывает последнее proactive message: анализ тренировки, план на сегодня, итоги недели, предупреждение о перегрузке, паузу в тренировках, напоминание о забеге, снижение выполнения плана и milestone-события прогресса (рост VDOT, рекорд объёма, серия тренировок, цель достижима).

Модуль `coach_tip` в `dashboardConfig.js`.

#### 3.4 AI-метки в календаре

`WeekCalendar.jsx` — два индикатора:
- Жёлтая точка — ключевая тренировка (`is_key_workout`)
- Зелёная точка — перевыполнение плана (actualKm > plannedKm × 1.15)

---

### Фаза 4: Контекст и промпты

#### 4.1 История тренировок в контексте

`ChatContextBuilder::formatRecentWorkouts` — последние 4 тренировки за 28 дней включаются в контекст LLM автоматически.

#### 4.2 Память о здоровье

- Таблица `user_health_events` (issue_type, description, severity, affected_area, days_off, resolved_at)
- `report_health_issue` tool теперь **сохраняет** запись в БД
- `ChatContextBuilder::formatActiveHealthIssues` — активные проблемы в контексте каждого чата

#### 4.3 Персонализация стиля AI-тренера

Поле `users.coach_style` (motivational / analytical / minimal):

| Стиль | Описание |
|-------|----------|
| `motivational` (по умолч.) | 2–4 предложения, похвала за конкретные достижения |
| `analytical` | 3–5 предложений, с цифрами и конкретикой, без лишних эмоций |
| `minimal` | 1–2 предложения, только суть |

Выбирается в настройках (вкладка «Тренировки» → «Стиль AI-тренера»). System prompt адаптируется автоматически.

---

### Фаза 5: Тестирование

Ad-hoc тест-скрипты были удалены при оптимизации проекта. Юнит-тесты находятся в `tests/Unit/`.

---

## Итоговая архитектура

```
┌─────────────────────────────────────────────────────────────────────┐
│                         LLM (Qwen3-14B)                            │
│                      llama-server :8081                             │
│                   OpenAI-compatible API                             │
└───────────────────────────┬─────────────────────────────────────────┘
                            │
┌───────────────────────────┴─────────────────────────────────────────┐
│                      БЭКЕНД (PHP)                                   │
│                                                                     │
│  ChatService (оркестратор)                                          │
│    ├── ChatPromptBuilder                                            │
│    │     ├── buildCompressedSystemPrompt (coach_style → тон)        │
│    │     ├── buildToolResolutionMessages (сжатый для tools)         │
│    │     ├── appendChatSearchSnippet (поиск по истории)             │
│    │     └── appendRagSnippet (база знаний)                         │
│    ├── ChatContextBuilder                                           │
│    │     ├── buildContextForUser (профиль, план, нагрузка)          │
│    │     ├── formatRecentWorkouts (4 последние тренировки)          │
│    │     ├── formatActiveHealthIssues (user_health_events)          │
│    │     └── formatCoachingInsights (ACWR, compliance, тренды)      │
│    ├── ChatToolRegistry (26 tools)                                  │
│    │     ├── READ: get_plan, get_day_details, get_workouts,         │
│    │     │   get_stats, get_profile, get_training_load,             │
│    │     │   get_date, race_prediction                              │
│    │     ├── ANALYSIS: analyze_workout, get_training_trends,        │
│    │     │   compare_periods, get_weekly_review, get_goal_progress, │
│    │     │   get_race_strategy, explain_plan_logic                  │
│    │     ├── WRITE: log_workout, update_training_day,               │
│    │     │   add_training_day, delete_training_day,                 │
│    │     │   move_training_day, swap_training_days, copy_day,       │
│    │     │   recalculate_plan, generate_next_plan,                  │
│    │     │   update_profile, report_health_issue                    │
│    │     └── analyzePaceSplits, calculateHrZones, avgHrFromTimeline │
│    ├── ChatConfirmationHandler                                      │
│    │     ├── tryExecutePendingAction (metadata-based)               │
│    │     └── isProposal (proposal vs confirmation)                  │
│    ├── ChatStreamHandler (NDJSON + tool_executing events)           │
│    ├── ChatActionParser (sanitize + ACTION fallback)                │
│    └── ChatMemoryManager (авто-извлечение фактов)                   │
│                                                                     │
│  ProactiveCoachService                                              │
│    ├── postWorkoutAnalysis ←── WorkoutService.importWorkouts        │
│    ├── processDailyBriefings ←── cron daily_briefing.php            │
│    ├── processWeeklyDigests ←── cron weekly_digest.php              │
│    ├── generateMessage (event-driven) ←── cron proactive_coach.php  │
│    └── callLlmSimple (non-streaming LLM)                           │
│                                                                     │
│  GoalProgressService                                                │
│    ├── takeSnapshot ←── cron goal_progress_snapshot.php             │
│    ├── detectMilestones                                             │
│    └── getProgressSummary → weekly review, chat tool                │
│                                                                     │
│  WorkoutController::analyzeWorkoutAi (cached endpoint)              │
├─────────────────────────────────────────────────────────────────────┤
│                      ФРОНТЕНД (React)                               │
│                                                                     │
│  ChatScreen                                                         │
│    ├── Markdown rendering (react-markdown, lazy)                    │
│    ├── Stop generation (AbortController)                            │
│    ├── SendIcon (Lucide) + input unlocked during stream             │
│    ├── Thumbs up/down feedback (localStorage)                       │
│    ├── Tool execution indicators (getToolLabel)                     │
│    ├── Proactive message labels (post_workout/daily/weekly)         │
│    ├── Contextual quick replies (chatQuickReplies.js)               │
│    └── Auto-context from URL (?context=workout&date=...)            │
│                                                                     │
│  DayModal → кнопка «Спросить тренера» → /chat?context&date         │
│  WorkoutDetailsModal → вкладка «AI-анализ» → analyzeWorkoutAi      │
│  Dashboard → CoachTipWidget (последнее proactive message)           │
│  WeekCalendar → AI dots (key workout, exceeded plan)                │
│  SettingsScreen → coach_style selector (3 стиля)                    │
├─────────────────────────────────────────────────────────────────────┤
│                         ДАННЫЕ                                      │
│                                                                     │
│  user_health_events (травмы, болезни, усталость)                    │
│  goal_progress_snapshots (VDOT, km, compliance, ACWR)               │
│  chat_messages.metadata.pending_action (structured confirmations)   │
│  chat_messages.metadata.proactive_type (тип проактивного сообщения) │
│  users.coach_style (motivational / analytical / minimal)            │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Новые таблицы БД

| Таблица | Миграция | Назначение |
|---------|----------|------------|
| `user_health_events` | `migrate_user_health_events.php` | Хранение травм/болезней для AI-памяти |
| `goal_progress_snapshots` | `migrate_goal_progress_snapshots.php` | Еженедельные снимки прогресса |

**Столбец:** `users.coach_style` VARCHAR(20) DEFAULT 'motivational'.

---

## Все созданные файлы

### Бэкенд — сервисы

| Файл | Назначение |
|------|------------|
| `services/ChatToolRegistry.php` | 26 tools: определение, schema, выполнение |
| `services/ChatPromptBuilder.php` | System prompt, token budget, нормализация |
| `services/ChatConfirmationHandler.php` | Write-gate, pending actions, подтверждения |
| `services/ChatStreamHandler.php` | NDJSON streaming, think/action буферизация |
| `services/ChatActionParser.php` | Санитизация ответов, ACTION-блоки |
| `services/ChatMemoryManager.php` | Авто-извлечение фактов из диалогов |
| `services/ProactiveCoachService.php` | Проактивные AI-сообщения |
| `services/GoalProgressService.php` | VDOT-трекинг, milestones, snapshots |

### Бэкенд — скрипты

| Файл | Назначение |
|------|------------|
| `scripts/daily_briefing.php` | Cron: ежедневный AI-брифинг |
| `scripts/weekly_digest.php` | Cron: еженедельный AI-дайджест |
| `scripts/goal_progress_snapshot.php` | Cron: снимки прогресса |
| `scripts/migrate_user_health_events.php` | Миграция: таблица здоровья |
| `scripts/migrate_goal_progress_snapshots.php` | Миграция: таблица снимков |

### Бэкенд — тесты

Юнит-тесты находятся в `tests/Unit/` (PHPUnit). Ad-hoc тест-скрипты удалены.

### Фронтенд

| Файл | Назначение |
|------|------------|
| `src/components/Dashboard/CoachTipWidget.jsx` | Виджет «Совет тренера» |
| `src/components/Dashboard/CoachTipWidget.css` | Стили виджета |
| `src/screens/chat/chatQuickReplies.js` | Контекстные быстрые ответы |

---

## Cron-задачи

```crontab
# Проактивный тренер (каждые 15 мин)
*/15 * * * * php /var/www/planrun/planrun-backend/scripts/proactive_coach.php

# Еженедельное AI-ревью (воскресенье 20:00 Москва)
* * * * * php /var/www/planrun/planrun-backend/scripts/weekly_ai_review.php

# Ежедневный AI-брифинг (7:00 Москва)
0 7 * * * TZ=Europe/Moscow php /var/www/planrun/planrun-backend/scripts/daily_briefing.php

# Еженедельный AI-дайджест (воскресенье 20:00 Москва)
0 20 * * 0 TZ=Europe/Moscow php /var/www/planrun/planrun-backend/scripts/weekly_digest.php

# Снимки прогресса (понедельник 6:00 Москва)
0 6 * * 1 TZ=Europe/Moscow php /var/www/planrun/planrun-backend/scripts/goal_progress_snapshot.php
```

Все скрипты проактивной системы требуют `PROACTIVE_COACH_ENABLED=1` в `.env`.

---

## Переменные окружения

| Переменная | Значение | Описание |
|-----------|----------|----------|
| `LLM_CHAT_BASE_URL` | `http://127.0.0.1:8081/v1` | URL llama-server с Qwen3-14B |
| `LLM_CHAT_MODEL` | `qwen3-14b` | Активная модель |
| `PROACTIVE_COACH_ENABLED` | `0` / `1` | Проактивные AI-сообщения и авто-анализ |
| `CHAT_SEARCH_HISTORY` | `0` / `1` | Поиск по истории чата для контекста |
| `CHAT_RAG_ENABLED` | `0` / `1` | RAG из базы знаний |
| `PLANRUN_AI_API_URL` | URL | RAG + orchestration сервис |

---

## Хронология изменений

| Дата | Событие | План |
|------|---------|------|
| Март 2026 | Этап 1: Рефакторинг ChatService на 6 модулей, 8 новых аналитических tools, ChatMemoryManager, ProactiveCoachService, GoalProgressService | AI Coach System Upgrade |
| Март 2026 | Этап 1a: Детальные контракты tools, обогащение контекста (фазы, зоны, тренерские знания), stop button, send icon, thumbs feedback | LLM Deep PlanRun Integration |
| 29.03.2026 | Этап 2: Переход с Ministral на Qwen3-14B (git backup v1.9) | — |
| 29.03.2026 | Этап 3: Качество данных (cadence, pace splits, TRIMP), авто-реакции (post-workout, daily briefing, weekly digest), AI на всех экранах (DayModal, WorkoutDetails, Dashboard, Calendar), персонализация (coach_style, health memory), 41 тест | Deep AI Training Integration | |
