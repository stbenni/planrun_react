# Инструкция по загрузке проекта в GitHub

## Шаги для загрузки проекта в GitHub:

### 1. Создайте репозиторий на GitHub

1. Зайдите на https://github.com
2. Нажмите кнопку "+" в правом верхнем углу → "New repository"
3. Заполните:
   - **Repository name**: `vladimirov` (или другое имя)
   - **Description**: "PlanRun - Календарь тренировок"
   - Выберите **Private** (если хотите приватный репозиторий)
   - НЕ добавляйте README, .gitignore или лицензию (они уже есть)
4. Нажмите "Create repository"

### 2. Подключите локальный репозиторий к GitHub

После создания репозитория GitHub покажет инструкции. Выполните команды:

```bash
cd /var/www/vladimirov

# Добавьте remote (замените YOUR_USERNAME на ваш GitHub username)
git remote add origin https://github.com/YOUR_USERNAME/vladimirov.git

# Или если используете SSH:
# git remote add origin git@github.com:YOUR_USERNAME/vladimirov.git

# Переименуйте ветку в main (если нужно)
git branch -M main

# Загрузите код в GitHub
git push -u origin main
```

### 3. Если нужно использовать SSH ключ

Если хотите использовать SSH вместо HTTPS:

1. Проверьте наличие SSH ключа:
```bash
ls -la ~/.ssh/id_rsa.pub
```

2. Если ключа нет, создайте его:
```bash
ssh-keygen -t rsa -b 4096 -C "your_email@example.com"
```

3. Скопируйте публичный ключ:
```bash
cat ~/.ssh/id_rsa.pub
```

4. Добавьте ключ в GitHub:
   - Settings → SSH and GPG keys → New SSH key
   - Вставьте содержимое ключа

### 4. Проверка

После загрузки проверьте:
```bash
git remote -v
git log --oneline -5
```

## Дальнейшая работа

### Добавление изменений:
```bash
git add .
git commit -m "Описание изменений"
git push
```

### Получение изменений:
```bash
git pull
```

### Проверка статуса:
```bash
git status
```

## Важно

- Файлы в `uploads/` не загружаются в репозиторий (в .gitignore)
- Виртуальные окружения Python (`.venv/`) не загружаются
- `node_modules/` не загружается
- Конфигурационные файлы с паролями (`.env`) не загружаются
