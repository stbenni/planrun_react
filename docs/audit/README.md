# Полный аудит кодовой базы PlanRun

Дата: 2026-06-10. Покрытие: весь backend (api/, planrun-backend/ — ~89 000 строк PHP) и весь frontend (src/ — ~52 000 строк JS/JSX), всего ~141 000 строк, ~590 файлов, 3 871 символ в индексе. Каждый файл прочитан построчно; каждая функция/метод/компонент описан в справочнике. Кандидаты в мёртвый код и дубли верифицированы адверсариально (grep-свипы, граф достижимости импортов от main.jsx, собственный детектор копипаста, сверка с боевым crontab и systemd-юнитами).

## Структура

### 1. Справочник функций — `functions/` (24 файла, ~11 800 строк)

| Раздел | Файлы |
|---|---|
| Backend: entry-поинты, api/, telegram, scripts | [01-backend-entrypoints.md](functions/01-backend-entrypoints.md) |
| Backend: services (69 классов, 6 частей) | [02](functions/02-backend-services-1.md) · [03](functions/03-backend-services-2.md) · [04](functions/04-backend-services-3.md) · [05](functions/05-backend-services-4.md) · [06](functions/06-backend-services-5.md) · [07](functions/07-backend-services-6.md) |
| Backend: AI-пайплайн (генерация планов, промпты, валидаторы) | [08](functions/08-backend-ai-1.md) · [09](functions/09-backend-ai-2.md) |
| Backend: CLI/cron-скрипты | [10](functions/10-backend-scripts-1.md) · [11](functions/11-backend-scripts-2.md) |
| Backend: providers (интеграции), utils (парсеры), validators | [12](functions/12-backend-providers-validators-utils.md) |
| Backend: тесты (обзор, покрытие) | [13](functions/13-backend-tests.md) |
| Frontend: api-клиенты, сторы, сервисы, воркеры | [20](functions/20-frontend-core.md) |
| Frontend: utils и hooks | [21](functions/21-frontend-utils-hooks.md) |
| Frontend: экраны (3 части) | [22](functions/22-frontend-screens-1.md) · [23](functions/23-frontend-screens-2.md) · [24](functions/24-frontend-screens-3.md) |
| Frontend: компоненты (6 частей) | [25](functions/25-frontend-components-1.md) · [26](functions/26-frontend-components-2.md) · [27](functions/27-frontend-components-3.md) · [28](functions/28-frontend-components-4.md) · [29](functions/29-frontend-components-5.md) · [30](functions/30-frontend-components-6.md) |

`symbols/` — машиночитаемый индекс тех же 3 871 символов (TSV: символ, вид, файл, строка, exported).

### 2. Мёртвый код

- **[DEAD-CODE-BACKEND.md](DEAD-CODE-BACKEND.md)** — 20 подтверждённых мёртвых символов (~755 строк, включая `prepareFullPlanAnalysis` на 368 строк и `workout_types.php` целиком); 6 функций живут только в тестах (~133 строки, семейство `applyScheduleOverridesToUserData`); ~35 одноразовых/ручных скриптов (сверено с боевым crontab из 16 заданий и systemd); 12 полу-мёртвых позиций.
- **[DEAD-CODE-FRONTEND.md](DEAD-CODE-FRONTEND.md)** — 33 мёртвых файла (~6 660 строк): старый Dashboard (16 файлов), старый Calendar (4), Stats-barrel + WorkoutShareCard (10), пара chat-воркера (2), useWorkoutStore; 18 мёртвых экспортов + 26 лишних `export`; бонус: 10 осиротевших CSS (~3 620 строк). Суммарный потенциал удаления ~10 300 строк.

### 3. Дубли

- **[DUPLICATES-BACKEND.md](DUPLICATES-BACKEND.md)** — 86 групп проверено: 8 критичных, 16 семейств точных копий, 13 параллельных реализаций; ~850–900 строк потенциальной экономии.
- **[DUPLICATES-FRONTEND.md](DUPLICATES-FRONTEND.md)** — 59 позиций проверено: 9 критичных (4 с подтверждёнными пользовательскими багами), 16 групп решаются удалением мёртвого кода; ~600–700 строк экономии в живом коде.

## Главные находки (баги, обнаруженные по ходу аудита)

Реальные дефекты, а не стилистика — каждый подтверждён чтением кода (детали в соответствующих отчётах):

1. **Интервальные лейблы дашборда v3 никогда не строятся** — `(км|м)\b` в `WeekSectionV3.jsx:226` и `NextWorkoutSectionV3.jsx:143`: в JS `\b` не работает после кириллицы, регэксп не матчит «4×1 км»; в `TodayHeroV3.jsx:286` соседний баг — «4×1 км» парсится как `km=1`.
2. **DDL уведомлений разошёлся** — `migrate_all.php` не знает колонку `paused`, которую пишет `NotificationSettingsService.php:471`: на свежей БД INSERT упадёт.
3. **`push_race_countdown.php` отсутствует в боевом crontab** — пуш «до гонки N дней» молча не работает.
4. **`test_weekly_review_for_user.php` сломан** — вызывает функции из неподключённого `weekly_ai_review.php` (fatal).
5. **`GpxTcxParser.php:431`** — перенос секунд `$s += 60` вместо обнуления: темп «5:120» вместо «6:00»; рядом `paceFromKmAndMinutes` в 3 копиях объявлен `: string`, но возвращает `null` (латентный TypeError; в Coros уже исправлено).
6. **`register_api.php` ↔ `complete_specialization_api.php`** — форк ~200 строк: обе ветки используют неопределённую `$targetMarathonDate`, complete_specialization теряет `birth_month`/`timezone`.
7. **`ChatContextBuilder::getWorkoutsHistory`** — потерян `NOT EXISTS`-дедуп ручных/импортных тренировок: LLM-контекст может задваивать тренировки.
8. **`prompt_builder.php`** — `buildFormatResponseBlock($userData ?? $modifiedUser ?? null)` в 4 местах: `$userData` всегда определён, модифицированные данные никогда не передаются.
9. **ResultModal — урезанный форк парсинга AddTrainingModal** — не понимает темп в скобках `(5:50)` и «отдых N м»: одно и то же описание в одной модалке предзаполняется, в другой нет.
10. **Крипто-дубль PIN** — PBKDF2/AES-GCM скопирован в `PinAuthService`/`CredentialBackupService` с расхождением санитизации PIN.
11. **`PinInput`** — проп `length` игнорируется (захардкожено 4); `$errorText` в email-digest методах `NotificationSettingsService` молча выбрасывается.
12. **Тренерский UI разошёлся по копиям** — `daysToRace` ×5 (день гонки 0 vs null — таблица и оверлей показывают разное), `DISTANCE_LABELS` ×6, `SPEC_LABELS` ×3.

## Методика

1. **Фаза 1**: 24 параллельных агента, каждый построчно читал свой батч (~5–6 тыс. строк) и писал раздел справочника + TSV-индекс символов.
2. **Фаза 2**: grep-свип всех 3 871 символов по всей кодовой базе (классы/функции/методы PHP; для JS — граф достижимости статических импортов от `main.jsx` + свип именованных экспортов), затем адверсариальная верификация каждого кандидата (динамические вызовы, call_user_func/Reflection/array_map-коллбэки, new Worker/new URL, crontab, systemd, openapi).
3. **Фаза 3**: детектор копипаста (нормализованные 10-строчные окна, `_work/clones.txt` — 134 группы) + семантические дубли из фазы 1 (`_work/dup_candidates.md`), каждая группа верифицирована чтением обоих фрагментов с классификацией (точная копия / форк / параллельная реализация / осознанный / ложный).

Рабочие артефакты — в `_work/` (детектор, его вывод, список кандидатов).
