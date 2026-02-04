# Systemd для React dev‑сервера

Юнит собирается из `planrun-react.service`; `{{PROJECT_ROOT}}` подставляется при установке. Все пути — внутри проекта.

## Установка

Из корня проекта:

```bash
sudo ./deploy/install-systemd.sh
sudo systemctl enable planrun-react
sudo systemctl start planrun-react
```

## Управление

```bash
sudo systemctl start planrun-react
sudo systemctl stop planrun-react
sudo systemctl restart planrun-react
sudo systemctl status planrun-react
sudo journalctl -u planrun-react -f
```

## Проверка

После запуска приложение доступно на `http://localhost:3200` (порт в `vite.config.js`).
