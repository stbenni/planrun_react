# Единый стандарт CSS — PlanRun

Документ описывает общие правила и токены для всех экранов и компонентов фронтенда.

## 1. Источники стилей

- **Дизайн-система**: `src/styles/sports-colors.css` — цвета, семантика (--bg-primary, --text-primary), спейсинг, типографика, радиусы, тени, градиенты. Поддерживает светлую и тёмную тему (`[data-theme="dark"]`).
- **Глобальные**: `src/index.css` — сброс, body, шрифты; импортирует `sports-colors.css`, `dark-mode.css`, `animations.css`.
- **Дополнительные**: `src/styles/animations.css`, `src/styles/dark-mode.css` (компонентные переопределения для тёмной темы).

Не дублировать токены в `variables.css` — всё необходимое уже в `sports-colors.css`.

## 2. Цвета

Использовать только переменные, не хексы в экранных стилях.

| Назначение | Переменные |
|------------|------------|
| Фон страницы | `var(--bg-primary)`, `var(--bg-secondary)` |
| Текст основной/вторичный | `var(--text-primary)`, `var(--text-secondary)`, `var(--text-tertiary)` |
| Карточки | `var(--card-bg)`, `var(--card-border)` |
| Акцент/кнопки | `var(--primary-500)`, `var(--primary-600)` |
| Градиент кнопок/заголовков | `var(--gradient-primary)`, `var(--gradient-hero)` |
| Успех/ошибка/предупреждение | `var(--success-500)`, `var(--danger-500)`, `var(--warning-500)` |
| Нейтрали | `var(--gray-50)` … `var(--gray-900)` |

## 3. Типографика и отступы

- Размеры: `var(--text-xs)` … `var(--text-5xl)`.
- Начертания: `var(--font-normal)` … `var(--font-extrabold)`.
- Отступы: `var(--space-1)` … `var(--space-16)` (сетка 4px).

## 4. Радиусы и тени

- Радиусы: `var(--radius-sm)` … `var(--radius-full)`.
- Тени: `var(--shadow-sm)` … `var(--shadow-xl)`. В тёмной теме использовать те же имена (например, `var(--shadow-md)`), не `--shadow-dark-*`.

## 5. Переходы

- `var(--transition-fast)`, `var(--transition-base)`, `var(--transition-slow)`.

## 6. Правила по экранам

### 6.1 Общие

- Контейнер страницы: `max-width: 1400px`, `margin: 0 auto`, фон `var(--bg-primary)` или `var(--bg-secondary)`.
- Нижний отступ на мобильных: `padding-bottom: 80px` / `100px` под BottomNav.
- На десктопе при фиксированном TopHeader: `padding-top: 88px`.

### 6.2 Карточки

- Фон: `var(--card-bg)` (в тёмной теме уже переопределён).
- Граница: `var(--card-border)`.
- Радиус: `var(--radius-xl)` или `var(--radius-2xl)` (например, 16–20px).
- Тень: `var(--shadow-md)` / `var(--shadow-lg)`.

### 6.3 Кнопки

- Основная: `background: var(--primary-500)`, `color: white`, при hover — `var(--primary-600)`.
- Вторичная: `background: var(--gray-100)` / `var(--bg-tertiary)`, цвет текста `var(--gray-700)` / `var(--text-primary)`.
- Радиус: `var(--radius-lg)` (например, 12px).

### 6.4 Формы

- Поля: `border: 1.5px solid var(--gray-300)`, при focus — `border-color: var(--primary-500)`, `box-shadow: 0 0 0 3px var(--primary-50)`.
- Подписи: `color: var(--gray-800)` / `var(--text-primary)`, `font-weight: var(--font-semibold)`.
- Подсказки/мелкий текст: `color: var(--gray-500)` / `var(--text-secondary)`.

### 6.5 Экран авторизации (логин/регистрация)

- Фон: `var(--gradient-primary)` или `var(--gradient-hero)` (единый с приложением, оранжево-красный).
- Карточка: `var(--card-bg)`, `var(--radius-xl)`, тень из дизайн-системы.
- Кнопки и акценты: `var(--primary-500)` / `var(--gradient-primary)`.

### 6.6 Лендинг

- Герой: допускается градиент `var(--gradient-hero)` или тот же, что и в приложении.
- Кнопки: первичная — контраст к фону (например, белый текст на `var(--primary-500)` или белая кнопка с `color: var(--primary-500)`).

## 7. Именование классов

- Префикс экрана: `landing-*`, `login-*`, `register-*`, `settings-*`, `dashboard-*`, `stats-*`, `calendar-*`, `user-profile-*`.
- Избегать общих имён вроде `.progress-bar` на одном экране, если тот же класс используется в другом смысле (например, `.register-progress-bar` для шага регистрации).

## 8. Тёмная тема

- Все экраны должны корректно выглядеть при `[data-theme="dark"]` на `body`/корне.
- Не хардкодить белый/чёрный — использовать `var(--card-bg)`, `var(--text-primary)` и т.д.
- Дополнительные переопределения при необходимости — в `dark-mode.css` по классам компонентов.

## 9. Адаптив

- Брейкпоинты: 360px, 480px, 640px, 768px, 1024px.
- Десктоп: TopHeader виден от 1024px, BottomNav скрыт.
- Touch: кнопки не менее ~44px по высоте/ширине при необходимости.

## 10. Файловая структура

- Глобальные: `index.css`, `App.css`.
- Стили экранов: `src/screens/<Screen>.css`.
- Общие компоненты: `src/components/common/*.css`, `src/components/<Module>/*.css`.
- Общие стили экранов (например, авторизация): `src/styles/screens-auth.css` (опционально).

Ссылка на полную палитру и токены: `src/styles/sports-colors.css`.
