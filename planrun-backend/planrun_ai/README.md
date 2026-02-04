# 🤖 PlanRun AI Интеграция

## 📁 Структура

```
planrun-backend/planrun_ai/
├── planrun_ai_config.php          # Конфигурация PlanRun AI API
├── planrun_ai_integration.php     # Интеграция с PlanRun AI API (callAIAPI)
├── prompt_builder.php             # Построитель промптов для нашего проекта
├── plan_generator.php             # Генератор планов через PlanRun AI
├── generate_plan_async.php        # Асинхронная генерация планов
├── plan_saver.php                 # Сохранение планов в БД
├── create_empty_plan.php          # Создание пустого календаря
└── text_generator.php             # Генерация текстовых описаний
```

## 🚀 Использование

### Генерация плана

```php
require_once __DIR__ . '/planrun_ai/plan_generator.php';

$planData = generatePlanViaPlanRunAI($userId);
// $planData содержит план в формате PlanRun
```

### Проверка доступности

```php
require_once __DIR__ . '/planrun_ai/planrun_ai_config.php';

if (isPlanRunAIAvailable()) {
    // PlanRun AI система доступна
}
```

### Построение промпта

```php
require_once __DIR__ . '/planrun_ai/prompt_builder.php';

$prompt = buildTrainingPlanPrompt($userData, 'race');
// $prompt - готовый промпт для PlanRun AI API
```

## 🔧 Конфигурация

**planrun_ai_config.php:**
- `PLANRUN_AI_API_URL` - URL PlanRun AI API (по умолчанию: http://localhost:8000/api/v1/generate-plan)
- `PLANRUN_AI_TIMEOUT` - Таймаут запроса (300 секунд)
- `USE_PLANRUN_AI` - Включить/выключить PlanRun AI (true/false)

## 📊 Процесс генерации

1. **plan_generator.php** получает данные пользователя из БД
2. **prompt_builder.php** строит детальный промпт
3. **planrun_ai_integration.php** отправляет запрос на PlanRun AI API
4. **PlanRun AI API** (порт 8000) ищет документы в Qdrant и генерирует план через Qwen3 14B
5. **plan_saver.php** сохраняет план в БД (training_plan_weeks, training_plan_days, training_day_exercises)

## 🎯 Промпты

Система промптов учитывает:
- Все данные пользователя (пол, возраст, опыт, объем)
- Тип цели (health/race/weight_loss/time_improvement)
- Предпочтения (дни, время, ОФП)
- Ограничения по здоровью
- Требования к плану (научность, реалистичность)

## ✅ Готово к использованию!

Система использует локальную LLM (Qwen3 14B) с RAG для генерации планов через PlanRun AI.
