# PlanRun - как обновлять документацию

Документация в проекте поддерживается вручную. После изменения кода важно синхронно обновлять и обзорные разделы, и прикладные справочники.

## Что поддерживается вручную

- `docs/01-FRONTEND.md`
- `docs/02-BACKEND.md`
- `docs/05-CALL-GRAPH.md`
- `docs/07-AUTH-SECURITY.md`
- `docs/08-AI-SERVING-STACK.md`
- `docs/09-AI-MODULE-REFERENCE.md`
- `docs/10-FRONTEND-MODULE-REFERENCE.md`
- `docs/11-BACKEND-OPS-REFERENCE.md`
- `docs/12-BACKEND-APPLICATION-REFERENCE.md`
- `docs/13-FRONTEND-COMPONENT-REFERENCE.md`
- локальные README в `planrun-backend/controllers`, `services`, `repositories`

## Чек-лист после изменения кода

1. Обновить код и убедиться, что структура модуля понятна.
2. Если изменилась архитектура, поток данных или набор ролей/сценариев, поправить обзорный документ.
3. Проверить `docs/03-API.md` и `docs/04-FILES-REFERENCE.md`, если затронуты action'ы, файлы или зоны ответственности.
4. Проверить `git diff` по документации.
5. Если появился новый API action, дополнительно проверить `openapi.yaml`.

## Что обновлять по типу изменения

| Изменение | Обновить вручную |
|-----------|------------------|
| Новый экран / крупный UI-поток | `01-FRONTEND.md`, `13-FRONTEND-COMPONENT-REFERENCE.md`, при необходимости `05-CALL-GRAPH.md`, `04-FILES-REFERENCE.md` |
| Новый store / service / utility на фронтенде | `01-FRONTEND.md`, `10-FRONTEND-MODULE-REFERENCE.md`, `04-FILES-REFERENCE.md` |
| Новый backend action / controller method | `02-BACKEND.md`, `12-BACKEND-APPLICATION-REFERENCE.md`, `03-API.md`, при необходимости `05-CALL-GRAPH.md`, `openapi.yaml` |
| Новый backend service / repository / provider | `02-BACKEND.md`, `12-BACKEND-APPLICATION-REFERENCE.md`, локальный README слоя, `04-FILES-REFERENCE.md`, `11-BACKEND-OPS-REFERENCE.md` если затронут root/provider/ops-слой |
| Изменение генерации плана, skeleton path, validators, weekly adaptation | `02-BACKEND.md`, `05-CALL-GRAPH.md`, `09-AI-MODULE-REFERENCE.md`, при необходимости `08-AI-SERVING-STACK.md`, `04-FILES-REFERENCE.md` |
| Новая очередь, cron-задача или notification flow | `02-BACKEND.md`, `05-CALL-GRAPH.md`, `11-BACKEND-OPS-REFERENCE.md`, профильные docs (`07`/`08`) если затронуты, `04-FILES-REFERENCE.md` |
| Изменение экранных helper-модулей `src/screens/*`, `src/services/*`, `src/hooks/*`, `src/utils/*` | `01-FRONTEND.md`, `10-FRONTEND-MODULE-REFERENCE.md`, `04-FILES-REFERENCE.md` |
| Изменение `src/components/*` или структуры экранов `src/screens/*.jsx` | `01-FRONTEND.md`, `13-FRONTEND-COMPONENT-REFERENCE.md`, `04-FILES-REFERENCE.md` |
| Изменение root backend helper-файлов, bootstrap/config или скриптов `planrun-backend/scripts/*` | `02-BACKEND.md`, `11-BACKEND-OPS-REFERENCE.md`, `04-FILES-REFERENCE.md` |

## Минимальное правило

Если не уверены, достаточно ли обновили docs, сделайте две вещи:

1. Поправьте обзорный markdown, который объясняет смысл изменения.
2. Проверьте руками `03-API.md` и `04-FILES-REFERENCE.md`.
