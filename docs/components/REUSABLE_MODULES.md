# Переиспользуемые модули проекта

> **ВАЖНО**: Этот документ содержит список всех переиспользуемых модулей проекта. При создании нового модуля ОБЯЗАТЕЛЬНО добавьте его в этот файл.

**Последнее обновление**: 2026-01-26

---

## 📊 Статистические компоненты (`src/components/Stats/`)

Переиспользуемые модули для отображения статистики тренировок. Можно использовать в любом месте приложения.

### Графики

#### `ActivityHeatmap`
Календарь активности (для мобильных устройств).

**Файл**: `src/components/Stats/ActivityHeatmap.jsx`

**Использование**:
```jsx
import { ActivityHeatmap } from '../components/Stats';

<ActivityHeatmap data={chartData} />
```

**Props:**
- `data` (array) - массив объектов с полями: `date`, `dateLabel`, `distance`, `time`, `workouts`

**Где используется:**
- `src/screens/StatsScreen.jsx`

---

#### `DistanceChart`
Столбчатый график дистанции (для десктопов).

**Файл**: `src/components/Stats/DistanceChart.jsx`

**Использование**:
```jsx
import { DistanceChart } from '../components/Stats';

<DistanceChart data={chartData} />
```

**Props:**
- `data` (array) - массив объектов с полями: `date`, `dateLabel`, `distance`, `workouts`

**Где используется:**
- `src/screens/StatsScreen.jsx`

---

#### `WeeklyProgressChart`
График прогресса по неделям.

**Файл**: `src/components/Stats/WeeklyProgressChart.jsx`

**Использование**:
```jsx
import { WeeklyProgressChart } from '../components/Stats';

<WeeklyProgressChart data={chartData} />
```

**Props:**
- `data` (array) - массив объектов с полями: `date`, `distance`, `workouts`

**Где используется:**
- `src/screens/StatsScreen.jsx`

---

### Списки

#### `RecentWorkoutsList`
Список последних тренировок с возможностью показать все.

**Файл**: `src/components/Stats/RecentWorkoutsList.jsx`

**Использование**:
```jsx
import { RecentWorkoutsList } from '../components/Stats';

<RecentWorkoutsList 
  workouts={workoutsArray}
  api={api}
  onWorkoutClick={handleWorkoutClick}
/>
```

**Props:**
- `workouts` (array) - массив тренировок
- `api` (object, optional) - API клиент
- `onWorkoutClick` (function, optional) - обработчик клика на тренировку

**Где используется:**
- `src/screens/StatsScreen.jsx`

---

#### `AchievementCard`
Карточка достижения.

**Файл**: `src/components/Stats/AchievementCard.jsx`

**Использование**:
```jsx
import { AchievementCard } from '../components/Stats';

<AchievementCard 
  icon="🏆"
  title="Первая тренировка"
  description="Выполните первую тренировку"
  achieved={true}
/>
```

**Props:**
- `icon` (string) - эмодзи или иконка
- `title` (string) - заголовок
- `description` (string) - описание
- `achieved` (boolean) - достигнуто ли

**Где используется:**
- `src/screens/StatsScreen.jsx`

---

### Модальные окна

#### `WorkoutDetailsModal`
Модальное окно с деталями тренировки.

**Файл**: `src/components/Stats/WorkoutDetailsModal.jsx`

**Использование**:
```jsx
import { WorkoutDetailsModal } from '../components/Stats';

<WorkoutDetailsModal
  isOpen={isModalOpen}
  onClose={handleClose}
  date="2026-01-20"
  dayData={workoutData}
  loading={false}
/>
```

**Props:**
- `isOpen` (boolean) - открыто ли модальное окно
- `onClose` (function) - функция закрытия
- `date` (string) - дата тренировки (YYYY-MM-DD)
- `dayData` (object) - данные тренировки
- `loading` (boolean) - загружаются ли данные

**Где используется:**
- `src/screens/StatsScreen.jsx`

---

### Утилиты

#### `StatsUtils.js`
Утилиты для обработки данных статистики.

**Файл**: `src/components/Stats/StatsUtils.js`

**Экспортируемые функции:**

1. **`getDaysFromRange(range)`** - Вычисляет период в днях
   ```jsx
   const { days, startDate } = getDaysFromRange('month');
   ```

2. **`formatDateStr(date)`** - Форматирует дату в строку YYYY-MM-DD

3. **`formatPace(seconds)`** - Форматирует темп в формат MM:SS

4. **`processStatsData(workoutsData, allResults, plan, range)`** - Обрабатывает данные для вкладки "Обзор"

5. **`processProgressData(workoutsData, allResults, plan)`** - Обрабатывает данные для вкладки "Прогресс"

6. **`processAchievementsData(workoutsData, allResults)`** - Обрабатывает данные для вкладки "Достижения"

**Где используется:**
- `src/screens/StatsScreen.jsx`

---

## 📦 Централизованный экспорт

Все модули статистики экспортируются через `src/components/Stats/index.js`:

```jsx
import {
  ActivityHeatmap,
  DistanceChart,
  WeeklyProgressChart,
  RecentWorkoutsList,
  AchievementCard,
  WorkoutDetailsModal,
  processStatsData,
  processProgressData,
  processAchievementsData
} from '../components/Stats';
```

---

## 📝 Правила добавления новых модулей

1. **Создайте модуль** в соответствующей папке `src/components/`
2. **Добавьте экспорт** в `index.js` папки модуля
3. **ОБЯЗАТЕЛЬНО добавьте описание** в этот файл (`docs/components/REUSABLE_MODULES.md`)
4. **Укажите:**
   - Название модуля
   - Путь к файлу
   - Пример использования
   - Описание props
   - Где используется

---

## 🎯 Примеры использования в разных местах

### В Dashboard
```jsx
import { RecentWorkoutsList, DistanceChart } from '../components/Stats';
```

### В Calendar
```jsx
import { ActivityHeatmap } from '../components/Stats';
```

### В Profile
```jsx
import { AchievementCard } from '../components/Stats';
```

---

## 📚 Связанные документы

- Полная документация модулей: `src/components/Stats/README.md`
- Архитектура проекта: `docs/architecture/ARCHITECTURE_ANALYSIS_2026.md`
