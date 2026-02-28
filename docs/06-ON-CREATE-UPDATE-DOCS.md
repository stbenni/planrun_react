# PlanRun — обновление документации при создании нового

При добавлении любого нового элемента в проект — дописать его в соответствующие файлы документации.

**Правило Cursor:** `.cursor/rules/on-create-update-docs.mdc` (alwaysApply)

---

## Что обновлять

| Создал | Обновить файлы |
|--------|----------------|
| **Метод ApiClient** | impact-matrix.mdc, architecture-flow.mdc |
| **Компонент React** | impact-matrix.mdc, architecture-flow.mdc, 01-FRONTEND.md, 04-FILES-REFERENCE.md |
| **Экран (screen)** | impact-matrix.mdc, architecture-flow.mdc, 01-FRONTEND.md, 04-FILES-REFERENCE.md, App.jsx/AppTabsContent |
| **Action бэкенда** | api_v2.php, ApiClient.js, impact-matrix.mdc, architecture-flow.mdc, 02-BACKEND.md, 03-API.md, openapi.yaml |
| **Метод store** | impact-matrix.mdc, architecture-flow.mdc, 01-FRONTEND.md |
| **Утилита (utils, hooks, services)** | impact-matrix.mdc, 01-FRONTEND.md, 04-FILES-REFERENCE.md |
| **Контроллер/сервис бэкенда** | impact-matrix.mdc, architecture-flow.mdc, 02-BACKEND.md |

---

## Чек-лист

- [ ] impact-matrix.mdc
- [ ] architecture-flow.mdc
- [ ] docs/01-FRONTEND.md или 02-BACKEND.md
- [ ] docs/04-FILES-REFERENCE.md
- [ ] При новом API: ApiClient.js, api_v2.php, openapi.yaml
