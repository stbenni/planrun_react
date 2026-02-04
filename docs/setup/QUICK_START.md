# Быстрый старт

Все команды — из **корня проекта**. Проект самодостаточен, без зависимостей от других каталогов.

## Вариант 1: Dev‑сервер

```bash
npm install
./START_SERVER.sh
```

Приложение: `http://localhost:3200` (порт в `vite.config.js`).

## Вариант 2: Production (Apache + dist)

```bash
npm install
npm run build
sudo ./deploy/apply-apache.sh
```

Сайт: https://s-vladimirov.ru (DocumentRoot = `dist/`, API = `/api`).

## Деплой и systemd

- Apache: `sudo ./deploy/apply-apache.sh`  
- Systemd (dev‑сервер): `sudo ./deploy/install-systemd.sh`, затем `systemctl enable --now planrun-react`

Подробнее: `docs/setup/DEPLOY_SVLADIMIROV.md`.
