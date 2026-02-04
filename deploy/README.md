# Деплой

Скрипты используют только пути внутри проекта. `PROJECT_ROOT` = родитель каталога `deploy/`.

- **apply-apache.sh** — генерирует Apache vhost из `vladimirov-le-ssl.conf.template` (подставляет `{{PROJECT_ROOT}}`), копирует в `sites-available`, перезагружает Apache. Запуск: `sudo ./deploy/apply-apache.sh` из корня проекта.
- **install-systemd.sh** — генерирует `planrun-react.service` из `../planrun-react.service` (подставляет `{{PROJECT_ROOT}}`), копирует в `/etc/systemd/system/`. Запуск: `sudo ./deploy/install-systemd.sh` из корня проекта.
