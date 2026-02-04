# Настройка приложения

Все пути — в корне проекта. Нет зависимостей от внешних каталогов.

## Dev‑сервер

```bash
npm install
./START_SERVER.sh
```

Приложение: `http://localhost:3200`.

## Production (s-vladimirov.ru)

```bash
npm install
npm run build
sudo ./deploy/apply-apache.sh
```

См. `docs/setup/DEPLOY_SVLADIMIROV.md` и `docs/setup/QUICK_START.md`.
