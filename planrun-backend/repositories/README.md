# Репозитории (repository layer)

Репозитории изолируют SQL и возврат структурированных данных сервисному слою. Они не должны принимать на себя orchestration, права доступа или HTTP-логику.

## Текущие репозитории

- `BaseRepository.php` - общие helpers доступа к БД.
- `TrainingPlanRepository.php` - метаданные и строки плана.
- `WorkoutRepository.php` - workout log, manual workouts, timeline/laps и связанные выборки.
- `WeekRepository.php` - недели и тренировочные дни.
- `ExerciseRepository.php` - упражнения тренировочного дня и exercise library.
- `StatsRepository.php` - агрегирующие выборки для статистики и прогнозов.
- `ChatRepository.php` - conversations, messages, unread state.
- `NoteRepository.php` - заметки к дням и неделям.
- `NotificationRepository.php` - хранилище и чтение уведомлений/связанных сущностей.

## Принципы слоя

1. Репозиторий знает SQL и формат возвращаемых строк.
2. Репозиторий не принимает решение о бизнес-правилах сам.
3. Сервис может комбинировать несколько репозиториев в одном use-case.

## Где смотреть детали

- обзор backend-архитектуры: `/var/www/planrun/docs/02-BACKEND.md`
- глубокий manual reference application-слоя: `/var/www/planrun/docs/12-BACKEND-APPLICATION-REFERENCE.md`
- полный список файлов: `/var/www/planrun/docs/04-FILES-REFERENCE.md`
- методы репозиториев перечислены в `/var/www/planrun/docs/02-BACKEND.md`
