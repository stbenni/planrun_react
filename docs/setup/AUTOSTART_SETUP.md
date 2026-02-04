# Автозапуск React dev‑сервера

Юнит устанавливается из проекта (без внешних путей):

```bash
sudo ./deploy/install-systemd.sh
sudo systemctl enable planrun-react
sudo systemctl start planrun-react
```

Управление: `start` / `stop` / `restart` / `status`; логи: `journalctl -u planrun-react -f`.

Подробнее: `docs/setup/SETUP_SYSTEMD.md`, `docs/setup/DEPLOY_SVLADIMIROV.md`.
