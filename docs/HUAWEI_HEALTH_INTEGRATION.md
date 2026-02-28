# Интеграция Huawei Health Kit — инструкция

Инструкция по получению доступа к Huawei Health Kit API и настройке интеграции в PlanRun.

---

## 1. Регистрация и создание проекта

1. Зарегистрируйтесь на [developer.huawei.com](https://developer.huawei.com/consumer/en/).
2. Войдите в [AppGallery Connect](https://developer.huawei.com/consumer/en/service/josp/agc/index.html).
3. Создайте проект: **My projects** → **Add project** → введите имя.
4. Добавьте приложение: **Add app** → выберите платформу (Android) → укажите:
   - App name
   - Package name (например, `com.planrun.app`)
   - Category

---

## 2. Включение Health Kit

1. В проекте: **Project settings** → **Manage APIs**.
2. Найдите **Health Kit** и включите его.
3. Нажмите **Apply for Health Kit**.
4. Выберите нужные права доступа:
   - Activity data (тренировки)
   - History data (история за неделю/месяц/год)

Модерация заявки может занять несколько дней.

---

## 3. OAuth 2.0 credentials (client_id, client_secret)

1. В AppGallery Connect: **Project settings** → **General**.
2. В блоке **API management** или **OAuth 2.0 credentials**:
   - **Client ID** — это `client_id` (App ID).
   - **Client secret** — создаётся отдельно (кнопка **Create** / **Generate**).

Либо в [HUAWEI Developers Console](https://developer.huawei.com/consumer/en/console):
- **My projects** → проект → **Project settings** → **API management** / **Credentials** → создать OAuth 2.0 credentials.

---

## 4. Redirect URI

1. В настройках приложения найдите **OAuth 2.0** или **Redirect URI**.
2. Добавьте callback URL:
   ```
   https://your-domain.com/api/oauth_callback.php
   ```
3. URL должен совпадать с `HUAWEI_HEALTH_REDIRECT_URI` в `.env`.

---

## 5. Настройка PlanRun

В `planrun-backend/.env` добавьте:

```env
HUAWEI_HEALTH_CLIENT_ID=ваш_client_id
HUAWEI_HEALTH_CLIENT_SECRET=ваш_client_secret
HUAWEI_HEALTH_REDIRECT_URI=https://your-domain.com/api/oauth_callback.php
HUAWEI_HEALTH_SCOPES=https://www.huawei.com/healthkit/activity.read https://www.huawei.com/healthkit/historydata.open.month
```

---

## 6. Важные моменты

- **Синхронизация в облако**: в приложении Huawei Health на устройстве пользователя включить **Me → Privacy management → Sync data to cloud**.
- **Ограничения по регионам**: Health Kit может быть недоступен в некоторых странах.
- **REST API**: точный endpoint для activity records уточнять в [документации Health Kit](https://developer.huawei.com/consumer/en/hms/huaweihealth/).

---

## 7. Архитектура интеграции в PlanRun

| Компонент | Путь |
|-----------|------|
| OAuth callback | `api/oauth_callback.php` |
| Провайдер Huawei | `planrun-backend/providers/HuaweiHealthProvider.php` |
| Контроллер | `planrun-backend/controllers/IntegrationsController.php` |
| Токены | таблица `integration_tokens` |
| Тренировки | таблица `workouts` (поля `source`, `external_id`) |

API actions: `integration_oauth_url`, `integrations_status`, `sync_workouts`, `unlink_integration`.

---

## 8. Полезные ссылки

- [Health Kit — обзор](https://developer.huawei.com/consumer/en/hms/huaweihealth/)
- [AppGallery Connect](https://developer.huawei.com/consumer/en/agconnect)
- [OAuth 2.0 для Health Service Kit](https://medium.com/huawei-developers/how-to-obtain-an-access-token-for-huawei-health-service-kit-server-to-server-oauth-2-0-cced5e4a47ce)
- [FAQs REST API](https://xdaforums.com/t/faqs-about-using-health-kit-rest-apis.4529265/)
