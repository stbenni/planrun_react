# Статистические компоненты

Переиспользуемые модули для отображения статистики тренировок. Можно использовать в любом месте приложения.

## Компоненты

### Графики

#### `ActivityHeatmap`
Календарь активности (для мобильных устройств).

```jsx
import { ActivityHeatmap } from '../components/Stats';

<ActivityHeatmap data={chartData} />
```

**Props:**
- `data` (array) - массив объектов с полями: `date`, `dateLabel`, `distance`, `time`, `workouts`

#### `DistanceChart`
Столбчатый график дистанции (для десктопов).

```jsx
import { DistanceChart } from '../components/Stats';

<DistanceChart data={chartData} />
```

**Props:**
- `data` (array) - массив объектов с полями: `date`, `dateLabel`, `distance`, `workouts`

#### `WeeklyProgressChart`
График прогресса по неделям.

```jsx
import { WeeklyProgressChart } from '../components/Stats';

<WeeklyProgressChart data={chartData} />
```

**Props:**
- `data` (array) - массив объектов с полями: `date`, `distance`, `workouts`

### Списки

#### `RecentWorkoutsList`
Список последних тренировок с возможностью показать все.

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
- `api` (object, optional) - Клиент API
- `onWorkoutClick` (function, optional) - обработчик клика на тренировку

#### `AchievementCard`
Карточка достижения.

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

### Модальные окна

#### `WorkoutDetailsModal`
Модальное окно с деталями тренировки.

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

## Утилиты

### `getDaysFromRange(range)`
Вычисляет период в днях.

```jsx
import { getDaysFromRange } from '../components/Stats';

const { days, startDate } = getDaysFromRange('month');
```

**Параметры:**
- `range` (string) - период: `'week'`, `'month'`, `'quarter'`, `'year'`

**Возвращает:**
- `{ days: number, startDate: Date }`

### `processStatsData(workoutsData, allResults, plan, range)`
Обрабатывает данные для вкладки "Обзор".

```jsx
import { processStatsData } from '../components/Stats';

const stats = processStatsData(workoutsData, allResults, plan, 'month');
```

**Возвращает:**
```jsx
{
  totalDistance: number,
  totalTime: number,
  totalWorkouts: number,
  avgPace: string,
  chartData: array,
  planProgress: object,
  workouts: array
}
```

### `processProgressData(workoutsData, allResults, plan)`
Обрабатывает данные для вкладки "Прогресс".

### `processAchievementsData(workoutsData, allResults)`
Обрабатывает данные для вкладки "Достижения".

## Примеры использования

### В Dashboard

```jsx
import { RecentWorkoutsList, DistanceChart } from '../components/Stats';
import { processStatsData } from '../components/Stats';

const Dashboard = () => {
  const [workouts, setWorkouts] = useState([]);
  
  useEffect(() => {
    // Загружаем данные
    const loadData = async () => {
      const data = await api.getAllWorkoutsSummary();
      const stats = processStatsData(data, results, plan, 'month');
      setWorkouts(stats.workouts);
    };
    loadData();
  }, []);
  
  return (
    <div>
      <h2>Последние тренировки</h2>
      <RecentWorkoutsList workouts={workouts} />
      
      <h2>График активности</h2>
      <DistanceChart data={stats.chartData} />
    </div>
  );
};
```

### В Calendar

```jsx
import { ActivityHeatmap } from '../components/Stats';

const Calendar = () => {
  const chartData = [/* данные */];
  
  return (
    <div>
      <ActivityHeatmap data={chartData} />
    </div>
  );
};
```

### В Profile

```jsx
import { AchievementCard } from '../components/Stats';

const Profile = () => {
  return (
    <div>
      <AchievementCard 
        icon="🏆"
        title="10 тренировок"
        description="Выполните 10 тренировок"
        achieved={userWorkouts >= 10}
      />
    </div>
  );
};
```

## Стили

Все компоненты используют CSS классы из `StatsScreen.css`. Убедитесь, что стили подключены:

```jsx
import '../screens/StatsScreen.css';
```

Или создайте отдельный файл стилей для компонентов статистики.
