# PlanRun - Календарь тренировок

Персональный календарь тренировок с AI-планированием.

## Структура проекта

- `src/` - Исходный код React приложения
- `planrun-backend/` - PHP backend API
- `api/` - API endpoints и обертки
- `docs/` - Документация проекта

## Технологии

- **Frontend**: React 18, Zustand, React Router
- **Backend**: PHP, MySQL
- **API**: RESTful API с поддержкой JWT и сессий

## Установка

### Frontend
```bash
cd /var/www/vladimirov
npm install
npm run build
```

### Backend
Настроить PHP и MySQL согласно конфигурации в `planrun-backend/`

## Документация

- **[Индекс для ИИ](./docs/AI_PROJECT_INDEX.md)** — все API, зависимости, ссылки на детальные доки (рекомендуется для ИИ)
- [Обзор документации](./docs/README.md)
- [Текущее состояние](./docs/CURRENT_STATE_2026.md)
- [Архитектура](./docs/architecture/ARCHITECTURE_ANALYSIS_2026.md)
- [Профиль пользователя](./docs/PROFILE_DOCUMENTATION.md)
- [Внесение тренировок (ИИ)](./docs/ai-add-workouts-instruction.md)

## Лицензия

Private project
