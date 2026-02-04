# Проблема с версией Node.js

## Проблема
Vite 7 требует Node.js 20.19+ или 22.12+, а установлен Node.js 18.19.1

## Решения

### Вариант 1: Обновить Node.js (рекомендуется)

```bash
# Установить nvm (Node Version Manager)
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.0/install.sh | bash
source ~/.bashrc

# Установить Node.js 20
nvm install 20
nvm use 20

# Проверить версию
node --version  # Должно быть v20.x.x

# Установить зависимости
cd /var/www/planrun/react/web
npm install
npm run dev
```

### Вариант 2: Использовать Vite 5 (совместим с Node 18)

В `package.json` уже указана версия `vite: "5.4.11"`, но нужно убедиться что она установилась:

```bash
cd /var/www/planrun/react/web
rm -rf node_modules package-lock.json
npm install vite@5.4.11 --save-dev
npm install
```

### Вариант 3: Использовать Create React App (альтернатива)

CRA работает с Node.js 18:

```bash
cd /var/www/planrun
npx create-react-app react-web-cra
# Затем скопировать файлы из react/web/src
```

### Вариант 4: Production сборка без dev сервера

Можно собрать статическую версию на другой машине с Node 20+ и загрузить на сервер.

## Текущий статус

- Node.js: 18.19.1 (требуется 20.19+ для Vite 7)
- Vite в package.json: 5.4.11 (совместим с Node 18)
- Проблема: зависимости не устанавливаются

## Следующий шаг

Попробуйте обновить Node.js через nvm или используйте другую машину для сборки.
