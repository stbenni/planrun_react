# 🚀 Инструкция по отправке в GitHub

## Шаг 1: Создайте репозиторий на GitHub

1. Перейдите на: https://github.com/new
2. **Repository name:** `planrun_react`
3. Выберите **Private** (рекомендуется)
4. **НЕ** создавайте README, .gitignore или лицензию (они уже есть)
5. Нажмите **Create repository**

## Шаг 2: Отправьте код

После создания репозитория выполните одну из команд:

### Вариант A: Через Personal Access Token (PAT)

```bash
cd /var/www/s-vladimirov.ru
git push -u origin main
```

Когда попросит:
- **Username:** `st_benni`
- **Password:** вставьте ваш Personal Access Token (не пароль!)

**Как создать токен:**
1. GitHub → Settings → Developer settings → Personal access tokens → Tokens (classic)
2. Generate new token (classic)
3. Выберите scope: `repo` (полный доступ к репозиториям)
4. Скопируйте токен и используйте как пароль

### Вариант B: Через SSH (если ключ добавлен в GitHub)

```bash
cd /var/www/s-vladimirov.ru
git remote set-url origin git@github.com:st_benni/planrun_react.git
git push -u origin main
```

**Проверьте, добавлен ли SSH ключ в GitHub:**
```bash
cat ~/.ssh/id_ed25519.pub
```

Затем добавьте его в GitHub: Settings → SSH and GPG keys → New SSH key

### Вариант C: Использовать credential helper

```bash
cd /var/www/s-vladimirov.ru
git config --global credential.helper store
git push -u origin main
# Введите username и токен один раз, они сохранятся
```

## ✅ После успешного push

Код будет доступен по адресу:
**https://github.com/st_benni/planrun_react**

## 🔄 Для будущих обновлений

Просто выполняйте:
```bash
cd /var/www/s-vladimirov.ru
git add .
git commit -m "Описание изменений"
git push
```
