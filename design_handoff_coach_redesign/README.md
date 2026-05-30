# Handoff: Редизайн тренерской зоны PlanRun

## Что это

Пакет описывает редизайн **тренерской части PlanRun** — нового first-class UI для тренеров вместо текущего «гостевого режима», в котором тренер использует UI бегуна с подменой `viewContext={athleteSlug}`.

В папке `prototype/` лежит интерактивный HTML-прототип. **Это design reference, не production-код.** Не копируйте его 1-в-1 — задача в том, чтобы воссоздать дизайн в существующем кодовом окружении PlanRun (React 18 + Vite + Zustand + react-router) используя уже сложившиеся паттерны: CSS Modules через `*.css`-файлы рядом с компонентами, CSS-токены из `src/styles/sports-colors.css`, компоненты из `src/components/common/Icons.jsx`, `Modal.jsx`, и т.д.

## Точность

**High-fidelity.** Цвета, типографика, отступы, состояния и анимации продуманы. Все измерения в прототипе соответствуют итоговым. Воссоздавайте пиксель-в-пиксель используя CSS-токены проекта.

## Контекст и аудит

Перед началом обязательно прочитайте `PlanRun Audit.html` (в этой же папке) — там полный разбор существующего кода: что есть, как устроено, где болит. Особенно разделы 03 (Экран тренера), 04 (Календарь) и 09 (Матрица фич).

**Главные проблемы, которые мы решаем:**

1. У тренера нет своих экранов — он работает в UI бегуна через `?athlete=slug`.
2. Нет bulk-операций (назначить тренировку 5 атлетам — открыть 5 страниц).
3. Нет ленты событий (загрузки, пропуски, вопросы рассыпаны по нотификациям).
4. Нельзя сравнить атлетов между собой.
5. При drill-in в ученика теряется контекст списка.

---

## Структура редизайна

### Маршруты

Все экраны тренера живут под **новой ролевой веткой маршрутов**. Условие: `user.role === 'coach' || user.role === 'admin'`.

| Маршрут | Замена | Назначение |
|---|---|---|
| `/` | `AthletesOverviewScreen` → `CoachWorkspace` | Главный экран тренера (3 режима + KPI + bulk) |
| `/calendar` | без замены, но новая ширма «команда» | Календарь команды (см. ниже) |
| `/chat` | без замены | Чаты тренер ↔ ученик уже работают |
| `/stats?athlete=slug` | без замены | Сохраняется как есть |
| `/library` (новый) | — | Библиотека шаблонов тренировок |

Бегун остаётся на текущем `Dashboard.jsx` без правок в этом этапе.

---

## Экраны

### 1. CoachWorkspace — главный экран тренера (десктоп)

**Файл:** `src/screens/CoachWorkspace.jsx` + `.css`. Заменяет `AthletesOverviewScreen.jsx`.

**Прототип:** `prototype/PlanRun Redesign v2.html` → артборд **«Главный · Таблица + фильтры + KPI»**. Код: `prototype/src/v2-coach-desktop.jsx` функции `CoachShell` + `TableView`.

**Layout (1440×900, адаптивный до 1280):**

```
┌──────────────────────────────────────────────────────────────────┐
│ TopBar 60px:   logo · nav · search · 🔔 · avatar                 │
├──────────────────────────────────────────────────────────────────┤
│ Hero 124px:    «Сегодня N атлетов ждут вас» + 4 KPI-карточки     │
├──────────────────────────────────────────────────────────────────┤
│ Tabs row 64px: [Таблица / Сетка / Поток] + filter chips + CTA   │
├──────────────────────────────────────────────────────────────────┤
│ Main:          таблица атлетов (или сетка/поток)                 │
└──────────────────────────────────────────────────────────────────┘
```

#### 1.1 TopBar
- Высота 60px, фон `--bg-primary`, нижний бордер `--card-border`.
- Слева: лого 30×30 в `--radius-lg` с белой `P` на `--primary-500` + `planrun` (Montserrat 800, 17px, letter-spacing -0.02em).
- Навигация (gap 2px между пунктами): **Команда** (active) · **Поток** · **Календарь** · **Чат** · **Аналитика** · **Шаблоны**. Каждый — `padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 500; color: var(--text-secondary)`. Active: `background: var(--gray-100); color: var(--text-primary); font-weight: 700`. На пунктах с непрочитанным — оранжевая badge с числом (Jost 10px, padding 1px 6px, border-radius 999px).
- Справа: глобальный поиск 320px (`background: var(--gray-100); border-radius: 9px; padding: 7px 12px`) с placeholder «Поиск атлета, тренировки, шаблона…» и кейкепом `⌘K`. Затем 🔔-кнопка 36×36 и аватар 36×36 с инициалами.

#### 1.2 Hero
- Padding `20px 28px`, фон `--bg-primary`, нижний бордер.
- Слева: eyebrow «ВТОРНИК · 12 МАЯ · ДОБРОЕ УТРО, МИХАИЛ» (font-size 11, font-weight 700, letter-spacing 0.12em, uppercase, color `--text-tertiary`).
- H1: 32px Montserrat 800, letter-spacing -0.03em. Шаблон: «Сегодня **N** атлетов ждут вас». Где **N** — оранжевое число, считается как `risk_count + question_count`.
- Справа: 4 KPI-карточки 158px шириной, gap 10px. Каждая:
  - Border 1.5px тонированный (16% opacity tone color).
  - 28×28 иконка-плашка слева сверху с фоном `tone.bg` (см. ниже).
  - Лейбл 11px font-weight 600 letter-spacing 0.06em.
  - Большое число — Jost 44px font-weight 800 letter-spacing -0.04em line-height 1.

**KPI карточки (4 штуки):**
| label | source | tone |
|---|---|---|
| Требуют внимания | `athletes.filter(atRisk).length` | danger |
| Новые загрузки | `events.filter(kind=upload, today).length` | success |
| Без ответа | `events.filter(kind=question, unanswered).length` | info |
| Средн. compliance | `Math.round(avg(athletes.compliance) * 100) + '%'` | primary |

**Tone colors (на основе sports-colors.css):**
| tone | bg | text |
|---|---|---|
| primary | `--primary-50` (#FFF4F0) | `--primary-500` (#FC4C02) |
| success | `--success-100` (#DCFCE7) | #166534 |
| warning | `--warning-100` (#FEF9C3) | #92400E |
| danger | `--danger-50` (#FEE2E2) | `--danger-500` |
| info | `--info-100` (#DBEAFE) | #1E40AF |

#### 1.3 Tabs Row

Слева — segmented control «Таблица / Сетка / Поток» в `--gray-100` фоне. Активная вкладка: белый фон + box-shadow. Каждая включает короткий хинт под лейблом (10px, color `--gray-500`): «все метрики» / «тепловая карта» / «события».

Справа от tabs — горизонтальная полоса фильтр-чипов:
1. «Все · N» — без точки.
2. Чипы групп (`Марафон-осень`, `Полумарафон`, и т.д.) — каждый с цветной точкой 6×6 слева.
3. Разделитель (1px linе высотой 18px).
4. Спец-чипы «⚠ Риск · N» (красная точка), «↑ Свежие · N» (зелёная точка).

Чип: `padding: 6px 10px; background: white; border: 1px solid --gray-200; border-radius: 999px; font-size: 12; font-weight: 600`. Активный: чёрный фон, белый текст.

Самая правая кнопка — `+ Назначить тренировку` (primary, оранжевая, с shadow `0 4px 12px rgba(252,76,2,0.25)`).

#### 1.4 Таблица атлетов (View «Таблица»)

Контейнер: `background: white; border-radius: 14px; border: 1px solid --gray-200; overflow: hidden`.

**Заголовок:** padding `12px 14px`, gap 12, font-size 10, color `--gray-500`, font-weight 700, letter-spacing 0.08em, фон `--gray-50`, нижний бордер.

**Колонки:**
| Width | Header | Содержимое |
|---|---|---|
| 28px | (checkbox-all) | `<input type=checkbox>` 15×15, `accent-color: --primary-500` |
| flex 2 | АТЛЕТ | Avatar 36 + имя 14px 600 + (unread badge) + group-tag |
| 130px | ЦЕЛЬ | строка 1: цель (Марафон / 10 км / Здоровье). строка 2: цель время в Jost 12px |
| 88px | ДО ГОНКИ | Jost 15px 700 + дата dd мес. ниже мелко |
| 110px | НЕДЕЛЯ | Compliance-бар 48×4 + «4/5» Jost 13px 700 |
| 110px | 7 ДНЕЙ · ОБЪЁМ | Sparkline 70×22 + сумма в Jost 12px |
| 130px | СЕГОДНЯ ПО ПЛАНУ | цветная точка типа + название + (дист · темп) |
| 105px | АКТИВНОСТЬ | «Сегодня / N дн. назад» + (опц.) бейдж «↑ новая» |
| 75px right | VDOT | Jost 15px 700 + trend `+5%` зелёный |

**Строка:** padding `12px 11px`, gap 12, border-bottom, cursor pointer.
- При hover: `background: #FFF8F4` (very light orange).
- При выборе (checkbox checked): background `#FFF8F4` + border-left 3px `--primary-400`.
- При активном drill-in: background `--primary-50` + border-left 3px `--primary-500`.

**Аватар атлета:** размер 36, с `ring` 2px:
- если `atRisk` → ring `--danger-500`,
- если `freshUpload` → ring `--success-500`,
- иначе нет ring.

**Group tag (компонент):** `inline-flex gap 5; padding 2px 7px; border-radius 999px; font-size 11; font-weight 600`. Фон: `groupColor + '15'` (15% opacity), текст: `groupColor`. Слева — точка 5×5 групового цвета.

**Sparkline:** SVG-полоса, линия + полупрозрачная заливка снизу, точка на последнем значении. Цвет — `--primary-500`, если `atRisk` — `--danger-500`. Реализация — простая через `polyline` (без библиотек). См. `prototype/src/v2-shared.jsx` функция `V2.Sparkline`.

**Compliance bar:** контейнер 48px, высота 4px, фон `--gray-200`, скруглён. Внутри fill цвета:
- ≥80% → `--success-500`
- 50–80% → `--warning-500`
- <50% → `--danger-500`

#### 1.5 Bulk-bar (нижняя плашка действий)

Появляется когда `selected.size > 0`. Параметры:
- `position: absolute; left: 24; right: 24; bottom: 20; z-index: 5`.
- `background: --text-primary (#0F172A); color: white; border-radius: 12; padding: 12px 16px; gap: 12`.
- `box-shadow: 0 20px 40px rgba(0,0,0,0.2)`.

Слева: «Выбрано · N» (700) + список первых трёх имён через запятую (60% opacity).

Кнопки (`background: rgba(255,255,255,0.12); padding: 8px 14px; border-radius: 8; font-size: 12; font-weight: 600; color white`):
- ✎ Назначить тренировку → открывает мастер
- 📋 Применить шаблон…
- ✉ Сообщение группе
- ✕ — очистить выбор (фон transparent)

---

### 2. View «Сетка» (тепловая карта compliance)

**Прототип:** артборд «Сетка · тепловая карта compliance». Код: функция `GridView`.

**Layout:** CSS grid 4 колонки, gap 12.

**Тайл (карточка атлета):**
- `background: white; border: 1px solid --gray-200; border-radius: 14; padding: 14; padding-top: 16; cursor: pointer; overflow: hidden; position: relative`.
- Сверху — тонкая полоса 3px высотой во всю ширину, фон `--gray-100`. Внутри — fill `pct%` шириной цветом по compliance (success/warning/danger).
- Avatar 36 с ring + имя «Фамилия Имя.» (с инициалом, не full name) и цель ниже.
- Снизу слева — большое число `weekDone/weekTotal` в Jost 32px 800 letter-spacing -0.03em. `/N` мелкими 18px серым.
- Справа — VDOT 20px 700.
- Снизу — sparkline thick 200×32.
- Снизу-нижний ряд бейджей: «РИСК» (danger), «↑ Сегодня» (success), плюс справа «42д» оранжевым если daysToRace ≤ 60.

---

### 3. View «Поток» (события команды)

**Прототип:** артборд «Поток · события команды». Код: `StreamView`.

**Layout:** одноколоночный список max-width 920px, центрированный.

**Карточка события:**
- `background: white; border: 1px solid --gray-200; border-radius: 14; padding: 14px 18px; cursor: pointer; display: flex; align-items: center; gap: 14`.
- При `isActive` (выбран в drill-in): border `--primary-500`.
- Слева — avatar 44 с ring цвета `tone.solid`.
- Центр: имя + group-tag в ряд, время справа («12 мин назад»). Под ним — заголовок события (14px 600) + детали (13px ink2).
- Справа — CTA-кнопка цвета `tone.solid`, белый текст: «Похвалить →», «Связаться», «Ответить», «Скорректировать план».

**Виды событий (`event.kind` + `tone`):**
| kind | tone | иконка |
|---|---|---|
| upload | success | ↑ |
| risk | danger | ! |
| risk (compliance) | warn | ! |
| question | info | ? |
| pr (личный рекорд) | primary | ★ |

---

### 4. Drill-in оверлей (slide-in панель)

**Прототип:** артборд «Drill-in оверлей · Алексей Петров». Код: функция `AthleteOverlay`.

Открывается **поверх** текущего экрана (не смена страницы) при клике на любого атлета в любом из 3 view'ов. Сохраняет контекст списка.

**Параметры панели:**
- `position: fixed; top: 0; right: 0; bottom: 0; width: 480px; background: white; box-shadow: -20px 0 40px rgba(0,0,0,0.1); z-index: 100`.
- Анимация: slide-from-right 250ms cubic-bezier(0.2, 0.7, 0.3, 1).
- Scrim (затемнение фона): `position: fixed; inset: 0; background: rgba(15,23,42,0.4); z-index: 99`. Клик по scrim — закрыть.
- Закрытие также по Esc и кнопке ✕ в правом верхнем углу.
- URL должен меняться на `?athlete=slug&panel=open` — чтобы можно было поделиться ссылкой и кнопка Назад в браузере закрывала панель.

**Структура:**
1. **Header (padding 24, без нижнего padding):** Avatar 56 + большое имя 22px 800 letter-spacing -0.02em + group-tag и (цель · время) под ним + кнопка ✕.
2. **Note plate (опц.):** Если у атлета есть актуальная заметка (свежая загрузка / пропуск / PR), показать плашку в `tone.bg` фоне с текстом причины.
3. **Quick actions (padding 16px 24px):** 4 кнопки в ряд (flex: 1 each, gap: 6), padding 12px 8px, border-radius 10:
   - **✉ Чат** (primary, оранжевый фон, белый текст)
   - **✎ Править план** (gray-100 фон)
   - **↔ Перенести** 
   - **📋 Шаблон**
4. **Tabs (padding 0 24px, нижний бордер):** Обзор / План недели / Графики / Чат · N. Active tab — оранжевая линия снизу 2px + 700 weight.
5. **Body (flex: 1; overflow: auto; padding: 24):** содержимое таба.

**Tab «Обзор»:**
- Grid 2×2 метрик: COMPLIANCE, ОБЪЁМ · 7 ДН, VDOT, ДО ГОНКИ. Каждая — gray-100 фон, border-radius 12, padding 14. Big number в Jost 28px 700.
- Section «СЕГОДНЯ ПО ПЛАНУ» — карточка с цветной полоской типа слева + название + (дист · темп) + кнопка «Открыть».
- Section «ОБЪЁМ · ПОСЛЕДНИЕ 7 ДНЕЙ» — большое число + sparkline 180×48.
- Section «СОБЫТИЯ» — мини-список событий по этому атлету (max 5).

**Tab «План недели»:**
- 7 строк (ПН-ВС). Структура: дата (Jost 16 700) + цветная полоска типа + название тренировки + (км) + бейдж «КЛЮЧ» для key workouts + кнопка ✎ для редактирования.
- Сегодняшний день — primary-50 фон + primary border 1.5px.
- Выполненные — зелёная галочка справа.
- Под списком — кнопка «+ Добавить тренировку» во всю ширину.

**Tab «Графики»:**
- VDOT с trend + sparkline 300×60.
- Grid 2×2 прогнозов времени: 5 км, 10 км, Полумарафон, Марафон. Каждый — gray-100 box, Jost 22px 700.

**Tab «Чат»:**
- Inline-чат с этим атлетом (превью последних 5-10 сообщений). Сообщения в bubble-стиле: атлет — gray bubble слева, тренер — primary-500 bubble справа белым текстом, скругление `14px 14px 14px 4px` / `14px 14px 4px 14px`.
- Внизу — input + кнопка отправки.
- Этот чат — это тот же `directMessages`, что и на `/chat`, просто встроен в overlay.

---

### 5. Мастер «Назначить тренировку» (модальное окно из 3 шагов)

**Прототип:** артборд «Мастер «Назначить тренировку»». Код: функция `BulkAssignModal`.

Открывается по `+ Назначить тренировку` (top-right в tabs row) или из bulk-bar.

**Параметры:**
- `position: fixed; top: 5%; left: 50%; transform: translateX(-50%); width: 720; max-height: 90%; z-index: 200`.
- `background: white; border-radius: 18; box-shadow: 0 30px 60px rgba(0,0,0,0.25)`.
- Scrim: `rgba(15,23,42,0.5)` под модалкой.

**Структура (header → step bar → body → footer):**
- Header (padding 20px 24px 14px): eyebrow «ШАГ N ИЗ 3» + динамический title (22px 800).
- Step bar: 3 полосы по 3px высотой, gap 6. Прошедшие/текущий — оранжевые, остальные — серые.
- Body (padding 20px 24px, overflow auto, flex: 1).
- Footer (padding 14px 24px, background gray-50, border-top): кнопка «← Назад» слева, «Дальше →» / «✓ Назначить» справа (последняя — зелёный фон success).

**Шаг 1 — Шаблон.** Grid 2 колонки, gap 10. Карточка шаблона:
- `border: 1.5px solid; border-radius: 12; padding: 14; cursor: pointer`.
- Активный: border `--primary-500`, background `--primary-50`.
- В шапке: эмодзи 28px + название (14px 700) + (дистанция · «использован N раз»).
- Под: описание шаблона (12px ink2).

Шаблоны идут из новой таблицы `workout_templates` (см. бэк-задачи ниже).

**Шаг 2 — Атлеты.** Сверху ряд chip'ов «+ вся группа «X»», «+ Все атлеты», «Очистить». Под ним — grid 2 колонки чек-боксов атлетов (avatar 28 + имя + цель). Можно выбрать любое сочетание группы и отдельных атлетов.

**Шаг 3 — Дата + сводка.** 
- Гей-карточка «СВОДКА»: эмодзи + название шаблона + описание.
- Chip'ы дат: «сегодня», «завтра», «послезавтра», «выбрать дату…» (открывает date-picker).
- Pills выбранных атлетов внизу.

Финальная кнопка — «✓ Назначить · N атлетам» (зелёная).

При нажатии → POST `/api/v2/bulk-assign` (см. бэк).

---

### 6. Coach Mobile (на ходу)

**Прототип:** артборд «Поток · события + быстрые ответы». Код: `src/v2-coach-mobile.jsx`.

**Layout 390×844:**
- Status bar 36px.
- Header: дата eyebrow + «На связи, Михаил» (24px 800) + avatar.
- 4 KPI-плашки в ряд (Риск / Загрузки / Вопросы / Атлетов).
- Tabs: «Поток N / Команда / Календарь».
- Список событий.
- Bottom-nav (Liquid Glass, position absolute bottom 12, blur 20px, 5 пунктов, активный — оранжевая иконка).

**Главное взаимодействие:** тап по карточке события → bottom-sheet с быстрым ответом и действиями (не уходя из ленты). Sheet содержит:
- Аватар, имя, время, заголовок события.
- Цветная плашка деталей.
- «БЫСТРЫЙ ОТВЕТ» — 3-4 шаблонных chip'а в зависимости от kind события («👍 Молодец!» / «Что случилось?» / «Сейчас расскажу») + textarea + кнопка отправки.
- «ИЛИ ДЕЙСТВИЕ» — 2×2 grid кнопок: 📋 Открыть план, ↔ Перенести, 📈 Графики, 🤖 Черновик AI.

---

### 7. Athlete Mobile (для бегуна)

**Прототип:** артборд «Сегодня · план + AI-совет тренера». Код: `src/v2-athlete-mobile.jsx`.

Это упрощённая версия текущего `Dashboard.jsx`. **Можно отложить на следующий этап**, основной фокус — тренерская часть.

Содержимое: AI-pill сверху от тренера → eyebrow «ТЕМПОВАЯ · КЛЮЧЕВАЯ» → Hero «4×1 км в темпе» (44px 800) → 3 метрики → бар интервалов → expand/collapse 9 отрезков → coach note card → CTA «Начать тренировку».

Tabs: Сегодня / Неделя / Цель / Прогресс.

---

## Дизайн-токены (используйте из проекта)

Все токены **уже определены** в `src/styles/sports-colors.css`. Используйте `var(--token-name)` и не вводите свои хексы.

### Цвета (брать из существующего CSS)

| Назначение | Token |
|---|---|
| Primary | `--primary-500` (#FC4C02) |
| Primary hover | `--primary-400` (#FF6B3D) |
| Primary darker | `--primary-600` (#E03D00) |
| Primary wash | `--primary-50` (#FFF4F0) |
| Primary soft | `--primary-100` (#FFE5D9) |
| Success | `--success-500` (#22C55E) |
| Warning | `--warning-500` (#EAB308) |
| Danger / Accent | `--danger-500` / `--accent-500` |
| Info | `--info-500` (#3B82F6) |
| Violet (SBU/control) | `#8B5CF6` (нет токена — добавьте `--violet-500`) |
| Text primary | `--text-primary` |
| Text secondary | `--text-secondary` |
| Text tertiary | `--text-tertiary` |
| Background base | `--app-background-base` |
| Card bg | `--card-bg` |
| Card border | `--card-border` |
| Gray 50–900 | `--gray-50` ... `--gray-900` |

### Семантические цвета типов тренировок

```css
--workout-easy:     #22C55E
--workout-tempo:    #EAB308
--workout-interval: #EF4444
--workout-long:     #3B82F6
--workout-control:  #8B5CF6
--workout-rest:     #A3A3A3
```

### Типографика

```css
font-family: 'Montserrat', -apple-system, sans-serif;  /* UI */
--font-stats: 'Jost', sans-serif;                       /* Цифры */
```

**Веса:** 400 (normal), 500 (medium), 600 (semibold), 700 (bold), 800 (extrabold).

**Шкала для редизайна (поверх существующих `--text-*`):**
- Hero H1 (главный экран): 32px Montserrat 800, letter-spacing -0.03em, line-height 1.1
- Section H2: 22px Montserrat 800, letter-spacing -0.02em
- Athlete name в drill-in: 22px 800 letter-spacing -0.02em
- Number stats: Jost 800, letter-spacing -0.04em (для крупных) или -0.02em (для средних)
- Eyebrow labels: 11px font-weight 700 letter-spacing 0.12em uppercase
- Section labels (внутри карточек): 10px font-weight 700 letter-spacing 0.08-0.1em
- Table headers: 10px font-weight 700 letter-spacing 0.08em
- Body text: 13-14px font-weight 400-500
- Caption: 11-12px color `--text-tertiary`

### Spacing, radii, shadows — брать из проекта

Существующие `--space-1..16`, `--radius-sm..2xl`, `--shadow-sm..xl`. **Не вводите свои.** Дополнительные:
- Modal shadow: `0 30px 60px rgba(0,0,0,0.25)`
- Overlay shadow: `-20px 0 40px rgba(0,0,0,0.1)`
- Bulk bar shadow: `0 20px 40px rgba(0,0,0,0.2)`
- Primary CTA shadow: `0 4px 12px rgba(252,76,2,0.25)`

### Liquid Glass — оставить как есть

Bottom-nav на мобильных, sheet'ы — продолжают использовать существующий стиль `--glass-bg` / `backdrop-filter: blur(20px)` из проекта.

---

## Интеракции и состояния

### CoachWorkspace

| Действие | Результат |
|---|---|
| Click на чекбокс атлета | Добавить в `selected: Set<id>`, показать bulk-bar внизу |
| Shift+Click | Range select (как в файл-менеджерах) |
| Click на строку атлета (вне чекбокса) | Открыть drill-in overlay |
| Click на tab «Таблица/Сетка/Поток» | Переключить view, сохранить выбор атлетов |
| Filter chip | Фильтр клиентский, не запрос на бэк |
| `⌘K` | Сфокусировать поиск (нужен глобальный хоткей) |
| Esc (при open overlay) | Закрыть overlay |
| Esc (при open модалке) | Закрыть модалку |
| Drag-and-drop атлета на другую группу (опц., не в MVP) | Move to group |

### Drill-in overlay

- Открывается через `navigate(`/?athlete=${slug}&panel=open`)`.
- Закрытие: ✕, scrim click, Esc, browser back — все ведут к `navigate(-1)` или `navigate('/')`.
- При закрытии — fade+slide-out 200ms.
- Внутри tab'ов навигация не меняет URL, только локальный state.

### Bulk-assign модалка

- Step bar анимируется при переходах (250ms ease).
- Кнопка «Дальше» отключена пока:
  - Step 1: не выбран шаблон.
  - Step 2: `selected.size === 0`.
- При финальном «Назначить»: показать toast «Назначено N атлетам» + закрыть модалку.

### Live updates

- Используйте существующий `useWorkoutRefreshStore` для refresh атлетов после Strava-webhook.
- KPI и количество в badge'ах обновлять при каждом refresh.
- В режиме «Поток» — новые события вставлять сверху с slide-in анимацией 300ms.

---

## State management

Используйте Zustand (как в проекте). Новый стор:

```js
// src/stores/useCoachStore.js
const useCoachStore = create((set, get) => ({
  athletes: [],
  events: [],
  templates: [],
  groups: [],
  
  // UI state
  view: 'table',           // 'table' | 'grid' | 'stream'
  selected: new Set(),
  filterGroup: 'all',      // 'all' | groupId | 'risk' | 'fresh'
  activeAthleteId: null,   // for drill-in
  bulkModalOpen: false,
  
  // Actions
  loadAll: async (api) => { ... },
  toggleSelected: (id) => { ... },
  selectGroup: (groupId) => { ... },
  bulkAssign: async ({ templateId, athleteIds, date }) => { ... },
}));
```

URL state синхронизируется через `useSearchParams` для `view`, `athlete`, `panel`.

---

## Бэк-задачи (новые API)

Текущий API уже имеет: `getCoachAthletes`, `getCoachGroups`, `getCoachRequests`. Нужны:

1. **`GET /api/v2/coach/events`** — лента событий тренера за сегодня. Возвращает:
   ```json
   { "events": [
     { "id": 1, "athlete_id": 1, "kind": "upload|risk|question|pr",
       "tone": "success|danger|warn|info|primary",
       "title": "...", "detail": "...", "created_at": "ISO",
       "cta_label": "Похвалить", "cta_action": "praise|reply|edit_plan|...",
       "context": { ... } }
   ] }
   ```
   Источники: новые workout-логи (Strava webhook), пропуски (cron-проверка compliance), unanswered messages в чате, PR-детектор.

2. **`GET /api/v2/workout-templates`** — список шаблонов тренировок тренера. Возвращает:
   ```json
   { "templates": [
     { "id": "t1", "name": "Темповый 4×1 км", "type": "tempo",
       "distance": 8, "emoji": "⚡", "description": "...",
       "uses_count": 24, "created_at": "ISO" }
   ] }
   ```

3. **`POST /api/v2/workout-templates`** — создать шаблон.
4. **`POST /api/v2/bulk-assign`** — массовое назначение:
   ```json
   { "template_id": "t1", "athlete_ids": [1, 5, 9], "date": "2026-05-13" }
   ```
   Сервер создаёт plan_day на эту дату каждому атлету; учитывает наличие существующего plan_day (опция overwrite/skip).

5. **`GET /api/v2/coach/kpi`** — агрегаты для hero-карточек:
   ```json
   { "at_risk_count": 2, "fresh_uploads": 4, "unanswered": 3, "avg_compliance": 0.82 }
   ```

---

## Прогрессивная имплементация

Делайте по фазам, чтобы могли тестировать постепенно.

**Фаза 1 — Каркас (1-2 дня)**
- [ ] Новый маршрут `/` для `role === 'coach'` → `CoachWorkspace`.
- [ ] TopBar + Hero + Tabs row (без функциональности).
- [ ] View «Таблица» базовая (read-only, без выбора).
- [ ] Drill-in overlay базовый (только tab «Обзор»).

**Фаза 2 — Bulk и контекстные действия (2-3 дня)**
- [ ] Чекбоксы и bulk-bar.
- [ ] Мастер «Назначить тренировку» (3 шага).
- [ ] Новый бэк-эндпойнт `POST /bulk-assign`.
- [ ] Бэк-эндпойнт + UI для шаблонов тренировок.

**Фаза 3 — Поток событий (2-3 дня)**
- [ ] Бэк: `GET /coach/events` + сбор данных.
- [ ] View «Поток» + inline CTAs.
- [ ] Coach mobile.

**Фаза 4 — Сетка + расширения (1-2 дня)**
- [ ] View «Сетка» с тепловой картой.
- [ ] Сравнение 2-4 атлетов (можно добавить в overlay).
- [ ] Полировка анимаций.

**Фаза 5 — Athlete mobile (опционально)**
- [ ] Упростить Dashboard для бегуна согласно прототипу.

---

## Где смотреть прототип

1. Откройте `prototype/PlanRun Redesign v2.html` в браузере (нужны фонты из `prototype/fonts/`).
2. Все экраны видны на одном холсте.
3. **Каждый артборд интерактивен** — кликайте табы, чекбоксы, открывайте overlay, листайте мастер.
4. Можно открыть любой артборд на весь экран (стрелка в углу).

Код прототипа — React JSX без сборки (через `@babel/standalone`), читается как обычный React-код:
- `src/v2-shared.jsx` — данные + общие компоненты (Avatar, Sparkline, Compliance, GroupTag, toneStyles).
- `src/v2-coach-desktop.jsx` — `CoachShell` + 3 view'а + overlay + bulk-modal.
- `src/v2-coach-mobile.jsx` — `CoachMobile`.
- `src/v2-athlete-mobile.jsx` — `AthleteMobile`.

---

## Файлы для замены/создания

```diff
- src/screens/AthletesOverviewScreen.jsx     (заменить)
- src/screens/AthletesOverviewScreen.css     (заменить)
+ src/screens/CoachWorkspace.jsx              (главный)
+ src/screens/CoachWorkspace.css
+ src/components/Coach/AthleteTable.jsx       (view «Таблица»)
+ src/components/Coach/AthleteGrid.jsx        (view «Сетка»)
+ src/components/Coach/EventStream.jsx        (view «Поток»)
+ src/components/Coach/AthleteOverlay.jsx     (drill-in)
+ src/components/Coach/AthleteOverlay.css
+ src/components/Coach/BulkAssignModal.jsx
+ src/components/Coach/BulkAssignModal.css
+ src/components/Coach/HeroKpi.jsx
+ src/components/Coach/FilterChips.jsx
+ src/components/Coach/Sparkline.jsx          (или вынести в common)
+ src/components/Coach/GroupTag.jsx           (или вынести в common)
+ src/components/Coach/ComplianceBar.jsx
+ src/api/coachApi.js                         (добавить новые методы)
+ src/stores/useCoachStore.js
+ src/screens/coach-mobile/CoachMobileWorkspace.jsx  (мобайл-версия)
+ src/screens/coach-mobile/EventQuickReplySheet.jsx
~ src/components/AppLayout.jsx                (ветвить по role: coach → CoachWorkspace)
~ src/components/AppTabsContent.jsx           (изменить роут '/' для coach)
~ src/components/common/BottomNav.jsx         (новые лейблы для coach: Поток / Команда)
```

---

## Что точно сохранить (не трогать)

- Цвета и токены из `sports-colors.css`.
- Шрифты: Montserrat + Jost.
- AI-чат с tool-calling (`api/chat_sse.php`) — он критичен.
- `WorkoutCard.jsx` — переиспользовать без изменений в drill-in tab «План недели» и в любых местах с тренировками.
- Логика расчёта compliance из `useDashboardData.js` (`hasWorkoutForCategory`, `buildProgressDataMap`) — перенести в coach-store as-is.
- Календарь команды на десктопе — пусть остаётся текущий `CalendarScreen.jsx` но с возможностью fильтрации по группе и просмотра нескольких атлетов сразу (это будет фаза 4).
- Strava/Polar/Coros/Suunto интеграции — без изменений.

---

## Вопросы, которые возникнут

1. **Что если атлет в нескольких группах?** → Текущий код позволяет (`a.groups: []`). Group-tag показывайте первую; в drill-in — все.
2. **Что считать «событием»?** → Стартуйте с 4 типов: upload (новая тренировка через webhook), risk (compliance < 50% или 7+ дней без активности), question (новое сообщение от атлета в чат), pr (детектор PR на основе результатов).
3. **Можно ли отменить bulk-assign?** → Да. Добавьте в сводку «Перезапишет план у N атлетов» если кто-то уже имеет план на эту дату. Сервер возвращает diff после операции.
4. **Drill-in vs. полная страница атлета?** → Drill-in для быстрых действий. Если тренеру нужна вся история — внутри overlay кнопка «Открыть профиль» ведёт на `/{username}` (существующий публичный профиль).

---

## Контакт

Если что-то непонятно — прототип всегда первичен. Открывайте его в браузере и смотрите как оно должно работать. Все интеракции там работают по-настоящему.
