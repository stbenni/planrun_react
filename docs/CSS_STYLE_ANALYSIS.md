# Анализ стилей и кнопок сайта PlanRun

**Дата:** 08.02.2026  
**Рефакторинг:** 08.02.2026 — единая система кнопок и базовый класс карточек.  
**Единый стиль:** см. **`docs/DESIGN_SYSTEM.md`** — один источник правды для токенов, отступов и правил.

## 1. Общая архитектура CSS

### Загрузка стилей
- **Глобально** (`main.jsx` → `index.css`): подключаются **`sports-colors.css`** (единственный источник дизайн-токенов), `dark-mode.css`, **`buttons.css`** (единые кнопки), **`cards.css`** (базовый .card), `animations.css`. Шрифты Montserrat задаются в `index.css`.
- **Файл `variables.css`** не подключён в `index.css` и считается устаревшим; все токены заданы в `sports-colors.css`.
- Компоненты подключают свои CSS рядом с JSX; календарь дополнительно тянет `calendar_v2.css`, `short-desc.css`, `settings.css` (через SettingsScreen). В `calendar_v2.css` по-прежнему есть дублирующий блок `:root` для совместимости; при правках предпочтительно использовать переменные из sports-colors.

### Дизайн-токены (что есть хорошего)

| Категория | Файл | Содержимое |
|-----------|------|------------|
| Цвета | `sports-colors.css` | primary (Strava orange), success, accent/danger, warning, info, neutrals (gray-50…900), семантика: `--bg-primary`, `--text-primary`, `--card-bg`, `--card-border` |
| Тёмная тема | `sports-colors.css` + `dark-mode.css` | Переопределение переменных в `[data-theme="dark"]`, переопределение компонентов в dark-mode |
| Отступы | sports-colors / variables | `--space-1` … `--space-16` (база 8px) |
| Типографика | sports-colors | `--text-xs` … `--text-5xl`, `--font-light` … `--font-extrabold` |
| Радиусы | sports-colors | `--radius-sm` … `--radius-full` |
| Тени | sports-colors | `--shadow-sm` … `--shadow-2xl` (в sports-colors с оранжевым оттенком) |
| Переходы | sports-colors | `--transition-fast/base/slow` |

Единый стиль: спортивный оранжевый (Strava), тёмная тема в духе Nike Run Club, шрифт Montserrat.

---

## 2. Кнопки — после рефакторинга

### Единый источник: `src/styles/buttons.css`

Базовые классы **`.btn`**, **`.btn-primary`**, **`.btn-secondary`** заданы один раз и подключены глобально через `index.css`.

- **Размеры:** `padding: var(--space-3) var(--space-5)`, `border-radius: var(--radius-lg)`, `font-size: var(--text-sm)`, `font-weight: var(--font-semibold)`.
- **Модификаторы:** `.btn--sm`, `.btn--lg`, `.btn--block` (flex: 1 для кнопок в ряд).
- **Тёмная тема:** переопределения в `[data-theme="dark"]` в том же файле.

Дублирующие определения удалены из: **Modal.css**, **CalendarScreen.css**, **Dashboard.css**, **RegisterScreen.css**, **calendar_v2.css**. В этих файлах остались только контекстные дополнения (например, `.btn-dashboard` для размера, `.btn-share`, `.btn-print` в calendar_v2).

### Специализированные кнопки (отдельные классы)

| Класс | Где определён | Назначение |
|-------|----------------|------------|
| `.btn-landing`, `.btn-landing-primary/secondary` | LandingScreen.css, landing.css | Лендинг, CTA |
| `.btn-dashboard` | Dashboard.css | Кнопки на дашборде |
| `.btn-workout`, `.btn-start`, `.btn-details`, `.btn-missed` | WorkoutCard.css | Карточка тренировки |
| `.btn-copy`, `.btn-add`, `.btn-remove` | settings.css, calendar_access.css | Настройки, доступ к календарю |
| `.btn-result`, `.btn-mark-complete`, `.btn-today-action`, `.btn-delete-workout` | calendar_v2.css, calendar.css | Календарь: результат, отметить, сегодня, удалить |
| `.btn-back`, `.btn-share`, `.btn-print` | calendar_v2.css, calendar_access.css | Навигация, шаринг, печать |
| `.tab-button` | settings.css | Вкладки настроек |
| `.login-button`, `.biometric-button` | LoginScreen.css | Вход и биометрия |

Плюс: семантика ясная (где какая кнопка). Минус: размеры и радиусы заданы вручную (12px, 14px, 16px, 24px и т.д.), нет единого набора модификаторов.

### Hover-эффекты

- Часто: `transform: translateY(-1px)` или `translateY(-2px)`.
- Primary: как правило `background: var(--primary-600)`, иногда свой `box-shadow` с оранжевым.
- Разные длительности: 200ms, 250ms, 300ms и разные кривые (ease, cubic-bezier).

---

## 3. Блоки и карточки

### Карточки

- **workout-card** (WorkoutCard.css, DayModal и др.): свой border-left, радиус, отступы.
- **progress-card**, **stat-metric-card**, **achievement-card**: упоминаются в dark-mode.css для фона/границы, но сами стили разбросаны по Dashboard, StatsScreen и т.д.
- **week-day-cell**: стили в WeekCalendar.css, тёмная тема в dark-mode.

Общее: семантика «карточка» есть, но нет одного базового класса типа `.card` с модификаторами (например, `.card--workout`, `.card--metric`).

### Контейнеры страниц

- `.dashboard`, `.stats-screen`, `.container` (CalendarScreen): каждый со своими `max-width: 1400px`, `padding`, `padding-bottom` под BottomNav, на десктопе `padding-top` под TopHeader.
- Паттерн повторяется, но значения (20px / 24px, 100px / 80px снизу) слегка отличаются.

---

## 4. Проблемы и риски

1. **Дублирование токенов**  
   `variables.css` и `sports-colors.css` оба задают :root (primary, gray, spacing, radius, shadows). В проекте в индекс попал только sports-colors — возможна путаница, если где-то импортируют variables.

2. **Нет единого набора кнопок**  
   Глобальные `.btn` / `.btn-primary` / `.btn-secondary` переопределяются в 5+ файлах → разное поведение на разных страницах и при изменении порядка импортов.

3. **Жёстко заданные размеры**  
   Много `padding: 12px`, `border-radius: 16px` вместо `var(--space-3)`, `var(--radius-xl)` — сложнее менять систему отступов/радиусов одним местом.

4. **Глобальные селекторы**  
   Классы вроде `.btn`, `.btn-primary` глобальные; нет соглашения (BEM, префикс компонента), из-за чего стили экранов и общих компонентов могут конфликтовать.

5. **Тени**  
   В sports-colors тени с оранжевым оттенком; в variables — нейтральные. Используются оба набора в зависимости от файла.

---

## 5. Рекомендации и сделанный рефакторинг

### Выполнено (08.02.2026)

- **Единый источник кнопок:** `src/styles/buttons.css` — базовые `.btn`, `.btn-primary`, `.btn-secondary`, модификаторы `.btn--sm`, `.btn--lg`, `.btn--block`. Дубликаты удалены из Modal, CalendarScreen, Dashboard, RegisterScreen, calendar_v2.
- **Базовый класс карточек:** `src/styles/cards.css` — `.card`, `.card--compact`, `.card--interactive`. Подключён глобально; существующие компоненты (workout-card, stat-metric-card) можно постепенно переводить на использование `.card` или оставить как есть.

### Краткосрочно (дальнейшие шаги)

- В новых и при правках старых стилей **использовать токены**: `var(--space-*)`, `var(--radius-*)`, `var(--text-*)` вместо «магических» px.
- По возможности **убрать дублирование** переменных: оставить один файл токенов (sports-colors) и не подключать variables.css глобально.

### Среднесрочно

- Описать **палитру кнопок** в документации: primary / secondary / ghost / danger и один набор размеров (default, large, small), все через классы-модификаторы.
- Зафиксировать **один набор теней** (либо нейтральный, либо с акцентом) и использовать его везде через переменные.
- Постепенно переводить **workout-card**, **stat-metric-card** на композицию с базовым `.card` (например, `class="card card--workout"`).

### Долгосрочно

- Перейти на **CSS-модули** или **один префикс** (например, `.pr-btn`, `.pr-card`) для компонентов, чтобы убрать глобальные конфликты.
- Вынести все дизайн-токены в один файл (или слои: tokens.css → theme-light.css / theme-dark.css), остальные файлы только используют переменные.

---

## 6. Сводка по кнопкам (где что искать)

| Контекст | Основные классы | Файл |
|----------|------------------|------|
| **Базовые (везде)** | .btn, .btn-primary, .btn-secondary, .btn--sm, .btn--lg, .btn--block | **src/styles/buttons.css** |
| Модалки | .form-actions + .btn | Modal.css (только разметка футера) |
| Календарь (общее) | .btn-share, .btn-print, .btn-result, .btn-today-action, .btn-mark-complete, .btn-delete-workout | calendar_v2.css |
| Регистрация | .btn .btn--block в .register-form-actions | RegisterScreen.css (override flex), RegisterScreen.jsx |
| Дашборд | .btn-dashboard (размер) + .btn .btn-primary/.btn-secondary | Dashboard.css |
| Карточка тренировки | .btn-workout, .btn-start, .btn-details, .btn-missed | WorkoutCard.css |
| Лендинг | .btn-landing, .btn-landing-primary, .btn-landing-secondary | LandingScreen.css, landing.css |
| Настройки | .settings-page .btn, .btn-copy, .btn-add, .btn-remove, .tab-button | settings.css |
| Логин | .login-button, .biometric-button | LoginScreen.css |

Если нужно, следующий шаг — предложить конкретный рефакторинг одного экрана (например, только кнопки календаря или только модалок) с патчем кода и списком правок по файлам.
