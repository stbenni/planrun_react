# Handoff: Полный редизайн PlanRun (тренер + бегун, light/dark, liquid glass)

## Что это

Пакет описывает финальный редизайн **обеих сторон PlanRun** — тренерской и бегунской — на основе аудита текущего кода. В папке `prototype/` лежит интерактивный HTML-прототип на 21 артборде, в котором работают все клики, табы, оверлеи, фильтры, переключатели темы.

**Это design reference, не production-код.** Не копируйте 1-в-1 — задача воссоздать дизайн в существующем кодовом окружении PlanRun (React 18 + Vite + Zustand + react-router, CSS-токены из `src/styles/sports-colors.css`, существующие компоненты из `src/components/`).

---

## Сначала прочитайте контекст

1. **`PlanRun Audit.html`** — полный разбор текущего кода: что есть, как устроено, где болит. **Обязательно** перед началом.
2. **`Dashboard Suggestions.html`** — конкретные UX-проблемы дашборда бегуна и предложения по их решению.
3. **`prototype/PlanRun Redesign v2.html`** — открыть в браузере (нужны `prototype/fonts/`). Здесь всё работает по-настоящему:
   - Кнопка **☀ / 🌙** справа вверху — переключает light ↔ dark глобально
   - Кликабельные табы, фильтры, чекбоксы, drill-in оверлеи
   - Sticky-табы на мобайле скроллят к секциям
   - Bottom-nav (Variant C) с плавно перетекающим pill

---

## 21 артборд на холсте, поделены на 8 секций

### Тренерская часть

1. **Тренер · десктоп** (5 артбордов: главный · drill-in · сетка · поток · мастер «Назначить тренировку»)
2. **Тренер · мобильный** (1 артборд: поток + bottom-sheet с быстрыми ответами)

### Бегунская часть

3. **Бегун · онбординг и выбор режима** (4: 3 шага + поиск тренера)
4. **Бегун · чаты** (2: AI-чат и чат с тренером)
5. **Бегун · настройки режима** (2: AI / тренер с переключателем)
6. **Бегун · новый дэшборд (v3)** (3: мобайл AI · мобайл тренер · десктоп 2 колонки)
7. **Бегун · empty-states** (3: нет плана · план генерируется · день отдыха)
8. **Бегун · настройка дэшборда** (1: тумблеры виджетов + 3 пресета)

---

## Ключевые решения дизайна

### Тренер — first-class UI, не «гостевой режим»

**Проблема:** сейчас тренер использует UI бегуна через `?athlete=slug`. Это корень неудобства.

**Решение:** новый `CoachWorkspace` экран, заменяющий `AthletesOverviewScreen`. 3 режима работы:
- **Таблица** — плотная таблица атлетов с inline-метриками (compliance, sparkline, темп, VDOT, сегодня)
- **Сетка** — heatmap-style карточки атлетов
- **Поток** — лента событий (загрузки, риски, вопросы, PR) с inline-CTA

Сверху — KPI-карточки (требуют внимания / новые загрузки / без ответа / средний compliance), фильтр-чипы по группам, кнопка `+ Назначить тренировку`.

**Drill-in без потери контекста:** клик по атлету открывает slide-in overlay 480px справа, не смена страницы. Внутри 4 таба: Обзор · План недели · Графики · Чат.

**Bulk-операции:** чекбоксы в таблице → bottom-bar → мастер из 3 шагов (Шаблон → Атлеты → Дата). Можно выбрать группу одним кликом.

### Бегун — режим эксклюзивный AI или тренер

**Решение:** не оба сразу. Юзер выбирает в онбординге, может сменить через настройки. История сохраняется при смене.

- **AI** — дефолт, рекомендуется (70%+ юзеров будут на нём)
- **Тренер** — апгрейд от 3500 ₽/мес, с индикатором online/offline и средним временем ответа

Чаты остаются раздельные (AI / тренер), но в шапке всегда видно — какой режим активен (бейдж).

### Дэшборд бегуна — фиксированная структура, не drag-and-drop

**Проблема:** текущий конструктор виджетов сложный, ≥80% юзеров его не используют.

**Решение:** дефолтная структура из 5-10 секций (Сегодня · Следующая · Неделя · Цель · Форма · PR · ...). Кастомизация через **тумблеры + 3 пресета** (Простой / Средний / Профи). Виджет «Сегодня» зафиксирован (нельзя выключить). Полная аналитика **TrainingLoad** (TSB / ATL / CTL / ACWR) остаётся **на дэшборде**, без модалок.

**AI интегрирован в «Сегодня»:** реплика тренера прикреплена к карточке тренировки в `quote`-стиле (аватар + имя + время сверху, текст полной ширины снизу). Не отдельный баннер.

### Мобайл навбар — Variant C (минимальный с pill)

Высота 60px (вместо 72). У неактивных табов **только иконки**, у активной — **иконка + лейбл в оранжевом pill** с анимацией перетекания (340ms cubic-bezier `0.33, 1, 0.68, 1`). Бейджи непрочитанного на чате, dot-индикаторы на прогрессе.

**Последний таб = меню/профиль**, не «настройки» (настройки внутри профиль-drawer).

### Liquid Glass везде

Карточки используют `rgba(255,255,255,0.72)` + `backdrop-filter: blur(20px) saturate(1.16)` + warm orange border (`rgba(252,76,2,0.08)`) + многослойные shadows с inset highlight 70% сверху и warm orange glow снизу.

Hero-карточки (`TodayHero`, `modeCard`, `currentCard`) — усиленный glass: opacity 78%, blur 24px, border 12%, более выраженные тени.

**Shells** (фон экранов) — warm radial gradient: два оранжевых пятна (7% top-left, 5% bottom-right) поверх `linear-gradient #FAF7F3 → #F4F7FB`.

### Тёмная тема — один источник правды

`DarkLayer` оборачивает любой экран и применяет CSS-overrides через `[data-prdark="1"]` attribute selectors. Унифицировано:
- Shell: тот же warm radial над `linear-gradient #0F151D → #0B1015`
- Карточки: `rgba(28,34,43,0.62)` + blur(18px) saturate(1.16) — настоящее стекло на тёмном
- Borders: warm orange tint `rgba(252,76,2,0.14)`
- Текст: ink/ink2/ink3 → светлая шкала
- Все цветные washes (success/danger/warning/info) сохраняют семантику, темнее по фону, ярче по тексту

Включается одним toggle — все 21 артборд переключаются в унисон.

---

## Файлы прототипа

```
prototype/
├── PlanRun Redesign v2.html         — главный, в браузере открывается этот файл
├── src/
│   ├── design-canvas.jsx            — холст с артбордами (для прототипа, не для production)
│   ├── tweaks-panel.jsx             — Tweaks-панель (light/dark toggle)
│   ├── v2-shared.jsx                — общие данные (атлеты, тренировки) + helpers (Avatar, Sparkline, Compliance)
│   ├── v2-coach-desktop.jsx         — CoachShell + 3 view'а + drill-in overlay + bulk-modal
│   ├── v2-coach-mobile.jsx          — мобильный экран тренера + bottom-sheet
│   ├── v2-athlete-mobile.jsx        — старая v2 бегунского мобайла (можно игнорировать, заменён v3-dashboard)
│   ├── v3-onboarding.jsx            — OnboardingFlow + FindTrainer
│   ├── v3-chats-settings.jsx        — AIChat + TrainerChat + ModeSettings
│   ├── v3-dashboard.jsx             — MobileDashV3 + DesktopDashV3 + EmptyStates + DashCustomizer + все виджеты
│   ├── v3-navbar.jsx                — MobileNav (Variant C — финальный) + 3 варианта (A/B/C — для справки)
│   ├── v3-dark-layer.jsx            — DarkLayer + CSS overrides (тёмная тема)
│   ├── v3-dark-theme.jsx            — V2_DARK токены
│   └── v3-dashboard-dark.jsx        — устарел, не используется (заменён DarkLayer)
└── fonts/                           — Montserrat + Jost
```

---

## Структура production-имплементации

### Маршруты

| Роль | Маршрут | Компонент | Заметка |
|---|---|---|---|
| Coach | `/` | `CoachWorkspace` | заменяет `AthletesOverviewScreen` |
| Coach | `/library` | `WorkoutTemplatesScreen` | новый — библиотека шаблонов |
| User | `/` | `Dashboard` v3 | переписать `Dashboard.jsx` |
| User | `/?athlete=slug` (coach view) | — | drill-in overlay вместо смены страницы |
| User | `/onboarding` | `OnboardingFlow` | новый — 3 шага: цель → режим → старт |
| User | `/find-trainer` | `FindTrainerScreen` | новый — при выборе режима «тренер» |
| User | `/settings/mode` | `ModeSettingsScreen` | новый — переключение AI ↔ тренер |
| Обе | `/chat` | `ChatScreen` | без изменений |
| Обе | `/calendar`, `/stats` | без изменений | пока без редизайна |

### Новые API-эндпойнты

1. **`GET /api/v2/coach/events`** — лента событий тренера за день
   ```json
   { "events": [
     { "id": 1, "athlete_id": 1, "kind": "upload|risk|question|pr",
       "tone": "success|danger|warn|info|primary",
       "title": "...", "detail": "...", "created_at": "ISO",
       "cta_label": "Похвалить", "cta_action": "praise|reply|edit_plan|..." }
   ] }
   ```
2. **`GET /api/v2/coach/kpi`** — агрегаты hero-карточек: `{ at_risk_count, fresh_uploads, unanswered, avg_compliance }`
3. **`GET /api/v2/workout-templates`** — шаблоны тренировок тренера
4. **`POST /api/v2/workout-templates`** — создать шаблон
5. **`POST /api/v2/bulk-assign`** — массовое назначение: `{ template_id, athlete_ids: [], date }`
6. **`POST /api/v2/user/switch-mode`** — смена режима AI ↔ тренер
7. **`GET /api/v2/trainers`** — список тренеров для FindTrainerScreen (с `online_status`, `avg_response_minutes`, `free_slots`, `rating`, `reviews`, `specs`, `price`)
8. **`POST /api/v2/trainers/{id}/apply`** — заявка на тренера

### Новый Zustand-стор

```js
// src/stores/useCoachStore.js
const useCoachStore = create((set, get) => ({
  athletes: [],
  events: [],
  templates: [],
  groups: [],
  view: 'table',          // 'table' | 'grid' | 'stream'
  selected: new Set(),
  filterGroup: 'all',
  activeAthleteId: null,  // drill-in
  bulkModalOpen: false,

  loadAll: async (api) => { ... },
  toggleSelected: (id) => { ... },
  selectGroup: (groupId) => { ... },
  bulkAssign: async ({ templateId, athleteIds, date }) => { ... },
}));

// src/stores/useTrainingModeStore.js
const useTrainingModeStore = create((set) => ({
  mode: 'ai',              // 'ai' | 'trainer'
  trainerId: null,
  switchMode: async () => { ... },
}));
```

URL state синхронизируется через `useSearchParams` для `view`, `athlete`, `panel`.

---

## Дизайн-токены — используйте свои + расширьте

Все базовые токены **уже определены** в `src/styles/sports-colors.css`. Используйте `var(--token-name)`, не вводите новые хексы.

### Что добавить в существующие токены

```css
/* Liquid glass surfaces */
--glass-card-bg: rgba(255, 255, 255, 0.72);
--glass-card-bg-strong: rgba(255, 255, 255, 0.78);
--glass-card-blur: blur(20px) saturate(1.16);
--glass-card-blur-strong: blur(24px) saturate(1.2);
--glass-card-border: rgba(252, 76, 2, 0.08);
--glass-card-border-strong: rgba(252, 76, 2, 0.12);
--glass-card-shadow:
  inset 0 1px 0 rgba(255, 255, 255, 0.7),
  0 12px 28px rgba(15, 23, 42, 0.06),
  0 4px 12px rgba(252, 76, 2, 0.04);
--glass-card-shadow-strong:
  inset 0 1px 0 rgba(255, 255, 255, 0.85),
  0 20px 40px rgba(15, 23, 42, 0.08),
  0 8px 20px rgba(252, 76, 2, 0.07);

/* App shell — warm radial gradient */
--app-shell-bg:
  radial-gradient(120% 80% at 0% 0%, rgba(252, 76, 2, 0.07) 0%, transparent 50%),
  radial-gradient(100% 70% at 100% 100%, rgba(252, 76, 2, 0.05) 0%, transparent 55%),
  linear-gradient(180deg, #FAF7F3 0%, #F4F7FB 100%);

[data-theme="dark"] {
  --glass-card-bg: rgba(28, 34, 43, 0.62);
  --glass-card-bg-strong: rgba(33, 41, 52, 0.78);
  --glass-card-border: rgba(252, 76, 2, 0.14);
  --glass-card-border-strong: rgba(252, 76, 2, 0.18);
  --glass-card-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.04),
    0 12px 28px rgba(0, 0, 0, 0.35),
    0 4px 12px rgba(252, 76, 2, 0.08);
  --app-shell-bg:
    radial-gradient(120% 80% at 0% 0%, rgba(252, 76, 2, 0.10) 0%, transparent 50%),
    radial-gradient(100% 70% at 100% 100%, rgba(252, 76, 2, 0.06) 0%, transparent 55%),
    linear-gradient(180deg, #0F151D 0%, #0B1015 100%);
}
```

Все existing-токены (primary, success, ink/ink2/ink3, workout colors и т.д.) — **не трогать**.

---

## Прогрессивная имплементация

Делайте по фазам, тестируйте после каждой.

### Фаза 1 — Каркас тренера (3-4 дня)
- [ ] Новый маршрут `/` для coach → `CoachWorkspace`
- [ ] TopBar + Hero + KPI-карточки + Tabs row
- [ ] View «Таблица» с фильтрами и чекбоксами
- [ ] Drill-in overlay (tab «Обзор» минимально)
- [ ] Подключить к существующим `coachApi.getCoachAthletes/Groups`

### Фаза 2 — Bulk и шаблоны (2-3 дня)
- [ ] Bulk-bar при `selected.size > 0`
- [ ] Мастер «Назначить тренировку» (3 шага)
- [ ] Бэк: `POST /bulk-assign`, `GET /workout-templates`
- [ ] UI создания шаблона

### Фаза 3 — Поток событий + сетка (3-4 дня)
- [ ] Бэк: `GET /coach/events` + источники (Strava webhooks, compliance cron, unanswered chat, PR detector)
- [ ] View «Поток» + inline CTA
- [ ] View «Сетка» с heatmap-тайлами
- [ ] Coach mobile + bottom-sheet

### Фаза 4 — Бегунский дэшборд v3 (3-4 дня)
- [ ] Переписать `Dashboard.jsx` под новую структуру
- [ ] Интегрировать AI в `TodayWorkoutCard` (quote-блок)
- [ ] Sticky-табы с smooth-scroll
- [ ] Dashboard customizer (тумблеры + 3 пресета)
- [ ] Mobile + Desktop 2-column

### Фаза 5 — Onboarding, выбор тренера, режим (3-4 дня)
- [ ] OnboardingFlow (3 шага)
- [ ] FindTrainerScreen
- [ ] ModeSettings + API смены режима
- [ ] Обновить чаты — индикаторы online/offline у тренера, capabilities-banner у AI

### Фаза 6 — Дизайн-система (1-2 дня)
- [ ] Добавить glass-токены в `sports-colors.css`
- [ ] Обновить `app-shell-bg` (warm radial)
- [ ] Light/Dark переключение через `[data-theme]`
- [ ] Применить liquid glass к `.card` классу
- [ ] Bottom-nav Variant C (анимация перетекания pill)

### Фаза 7 — Empty states + polish (1-2 дня)
- [ ] No-plan / generating / rest-day экраны
- [ ] Анимации, переходы
- [ ] Тестирование на реальных Android-устройствах (Capacitor)

---

## Файлы для замены/создания

```diff
- src/screens/AthletesOverviewScreen.jsx     (заменить)
- src/components/Dashboard/Dashboard.jsx     (переписать v3)
+ src/screens/CoachWorkspace.jsx             (главный тренера)
+ src/screens/OnboardingFlow.jsx
+ src/screens/FindTrainerScreen.jsx
+ src/screens/ModeSettingsScreen.jsx
+ src/components/Coach/AthleteTable.jsx
+ src/components/Coach/AthleteGrid.jsx
+ src/components/Coach/EventStream.jsx
+ src/components/Coach/AthleteOverlay.jsx
+ src/components/Coach/BulkAssignModal.jsx
+ src/components/Coach/HeroKpi.jsx
+ src/components/Coach/FilterChips.jsx
+ src/components/Coach/CoachMobile.jsx
+ src/components/Dashboard/v3/TodayHero.jsx          (с AI quote)
+ src/components/Dashboard/v3/NextWorkoutSection.jsx
+ src/components/Dashboard/v3/WeekSection.jsx
+ src/components/Dashboard/v3/GoalSection.jsx        (countdown + prediction в одной)
+ src/components/Dashboard/v3/FormSection.jsx        (с полным графиком TSB/ATL/CTL)
+ src/components/Dashboard/v3/PRSection.jsx
+ src/components/Dashboard/v3/RacePredictionSection.jsx
+ src/components/Dashboard/v3/PaceZonesSection.jsx
+ src/components/Dashboard/v3/StatsSection.jsx
+ src/components/Dashboard/v3/DashCustomizer.jsx
+ src/components/Dashboard/v3/EmptyStates.jsx
+ src/api/coachApi.js                                (+ getEvents, getKpi, bulkAssign)
+ src/api/trainingModeApi.js
+ src/api/trainersApi.js                             (расширить)
+ src/stores/useCoachStore.js
+ src/stores/useTrainingModeStore.js
~ src/styles/sports-colors.css                       (добавить glass-токены)
~ src/components/common/BottomNav.jsx                (Variant C из v3-navbar.jsx)
~ src/components/AppLayout.jsx                       (route by role)
```

---

## Критически важно сохранить

- **Strava-orange `#FC4C02`** как primary
- **Семантические цвета типов тренировок** (easy/tempo/interval/long/control) — это узнаваемая ДНК
- **Montserrat + Jost** — Jost для всех цифр и статистики
- **AI-чат с tool-calling** (`api/chat_sse.php`) — киллер-фича, не трогать backend
- **WorkoutCard.jsx** — переиспользуется везде, оставить как есть
- **TrainingLoad** аналитика (TSB / ATL / CTL / ACWR) — серьёзная фича, оставить полностью
- **Многоисточниковая выгрузка** — Strava + Polar + Coros + Suunto + Telegram + manual
- **Логика расчёта compliance** из `useDashboardData.js` (`hasWorkoutForCategory`, `buildProgressDataMap`) — перенести as-is в coach-store

---

## Как передать это вашему Claude Code

1. Скачайте этот пакет, распакуйте в корень репозитория PlanRun (или рядом)
2. Откройте Claude Code в этой папке
3. Скажите примерно так:

> Изучи `design_handoff_v2/README.md`, `PlanRun Audit.html` и `Dashboard Suggestions.html`. Открой `design_handoff_v2/prototype/PlanRun Redesign v2.html` в браузере — там работают все 21 артборд с переключением темы. Начни с **Фазы 1** (каркас CoachWorkspace), используя мои существующие токены из `src/styles/sports-colors.css` + добавь glass-токены из README. После каждой фазы покажи мне результат прежде чем идти дальше.

Это удержит его от попытки сделать всё сразу и сохранит итеративный темп.

---

## Если что-то непонятно

Прототип — первоисточник. Всё, что описано в README, можно посмотреть, кликнуть и проверить в браузере. Переключайте ☀ / 🌙, кликайте табы, открывайте drill-in оверлеи, листайте мастер «Назначить тренировку».
