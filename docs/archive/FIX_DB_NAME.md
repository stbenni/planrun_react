# 🔧 Исправление имени базы данных: running_calendar → sv

## ✅ Что уже исправлено

- ✅ `MIGRATION_GUIDE.md` - документация обновлена

## ⚠️ Требуется исправление (нужны права root/www-data)

### Файл: `planrun-backend/db_config.php`

**Текущее значение (строка 10):**
```php
define('DB_NAME', 'running_calendar'); // Новая БД для календаря тренировок
```

**Нужно заменить на:**
```php
define('DB_NAME', 'sv'); // База данных sv
```

**Также в комментарии (строка 5):**
```php
// Было: Используется для подключения к MySQL базе данных running_calendar
// Стало: Используется для подключения к MySQL базе данных sv
```

## 🚀 Команды для исправления

Выполните одну из команд (нужны права для записи):

### Вариант 1: Через sudo
```bash
cd /var/www/s-vladimirov.ru
sudo sed -i 's/running_calendar/sv/g' planrun-backend/db_config.php
sudo sed -i 's/Новая БД для календаря тренировок/База данных sv/g' planrun-backend/db_config.php
```

### Вариант 2: Вручную через редактор
```bash
cd /var/www/s-vladimirov.ru
nano planrun-backend/db_config.php
# Или
vim planrun-backend/db_config.php
```

Измените:
- Строка 5: `running_calendar` → `sv`
- Строка 10: `'running_calendar'` → `'sv'`
- Строка 10: комментарий `Новая БД для календаря тренировок` → `База данных sv`

### Вариант 3: Через временный файл
```bash
cd /var/www/s-vladimirov.ru
cat > /tmp/db_config_sv.php << 'EOF'
<?php
/**
 * Конфигурация подключения к базе данных
 * 
 * Используется для подключения к MySQL базе данных sv
 */

// Параметры подключения к БД
define('DB_HOST', 'localhost');
define('DB_NAME', 'sv'); // База данных sv
define('DB_USER', 'root');
define('DB_PASS', 'aApzbz8h2ben@');
define('DB_CHARSET', 'utf8mb4');

/**
 * Получить подключение к БД
 */
function getDBConnection() {
    static $conn = null;
    
    if ($conn === null) {
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            
            if ($conn->connect_error) {
                error_log("Ошибка подключения к БД: " . $conn->connect_error);
                return null;
            }
            
            $conn->set_charset(DB_CHARSET);
        } catch (Exception $e) {
            error_log("Ошибка подключения к БД: " . $e->getMessage());
            return null;
        }
    }
    
    return $conn;
}
EOF

sudo cp /tmp/db_config_sv.php planrun-backend/db_config.php
sudo chown www-data:www-data planrun-backend/db_config.php
sudo chmod 644 planrun-backend/db_config.php
```

## ✅ Проверка

После исправления проверьте:
```bash
cd /var/www/s-vladimirov.ru
grep -r "running_calendar" . --exclude-dir=node_modules --exclude-dir=.git --exclude-dir=dist 2>/dev/null
```

Должно остаться только упоминания в скрипте `fix_db_name.sh` (это нормально).

Проверьте, что DB_NAME исправлен:
```bash
grep "DB_NAME" planrun-backend/db_config.php
# Должно быть: define('DB_NAME', 'sv');
```

## 📋 Итоговый список изменений

1. ✅ `MIGRATION_GUIDE.md` - исправлено
2. ⚠️ `planrun-backend/db_config.php` - **требуется исправление с правами root/www-data**

После исправления все упоминания `running_calendar` будут заменены на `sv`.
