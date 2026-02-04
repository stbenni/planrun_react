# ✅ React приложение перенесено

## 📁 Новое расположение

React приложение перенесено из `/var/www/planrun/react/web/` в отдельную директорию:

```
/var/www/s-vladimirov.ru/
```

## 🔧 Systemd Service

**Новый сервис:** `s-vladimirov-react.service`
- enabled (автозапуск)
- Работает на порту 3200

**Старый сервис:** `planrun-react.service`
- disabled (отключен)

## 🌐 Доступ

- http://localhost:3200
- http://192.168.0.6:3200
- http://s-vladimirov.ru:3200 (после настройки DNS)

## 📝 Управление

```bash
# Статус
systemctl status s-vladimirov-react

# Перезапуск
systemctl restart s-vladimirov-react

# Логи
journalctl -u s-vladimirov-react -f
```

## ✅ Разделение

Теперь PlanRun и React приложение полностью разделены:
- PlanRun: `/var/www/planrun/`
- React: `/var/www/s-vladimirov.ru/`
