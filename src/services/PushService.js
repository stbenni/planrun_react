/**
 * Push-уведомления (FCM) для Capacitor.
 * Регистрация токена, отправка на бэкенд, обработка входящих уведомлений.
 */

import { Capacitor } from '@capacitor/core';
import { PushNotifications } from '@capacitor/push-notifications';
import TokenStorageService, { isNativeCapacitor } from './TokenStorageService';

let listenersRegistered = false;
/** Актуальный api для отправки токена (listener срабатывает асинхронно, нужна свежая ссылка) */
let currentApiRef = null;

/**
 * Зарегистрировать push и отправить токен на бэкенд.
 * @param {object} api - ApiClient из useAuthStore
 * @returns {{ ok: boolean, reason?: string }} ok=true если register() вызван, reason при ошибке
 */
export async function registerPushNotifications(api) {
  if (!isNativeCapacitor() || !api) {
    return { ok: false, reason: 'Только в приложении (APK)' };
  }
  currentApiRef = api;

  try {
    const permStatus = await PushNotifications.checkPermissions();
    let status = permStatus.receive;
    if (status === 'prompt' || status === 'prompt-with-rationale') {
      const result = await PushNotifications.requestPermissions();
      status = result.receive;
    }
    if (status !== 'granted') {
      return { ok: false, reason: 'Разрешите уведомления в настройках устройства' };
    }

    if (!listenersRegistered) {
      await setupListeners();
      listenersRegistered = true;
    }

    await PushNotifications.register();
    return { ok: true };
  } catch (e) {
    return { ok: false, reason: e?.message || 'Ошибка регистрации' };
  }
}

/**
 * Отписаться от push (при logout).
 */
export async function unregisterPushNotifications(api) {
  if (!isNativeCapacitor()) return;
  try {
    await PushNotifications.unregister();
    if (api) {
      const deviceId = await TokenStorageService.getOrCreateDeviceId();
      await api.request('unregister_push_token', { device_id: deviceId }, 'POST');
    }
  } catch (e) {
    if (process.env.NODE_ENV !== 'production') {
      console.warn('[Push] Unregister failed:', e?.message);
    }
  }
}

async function setupListeners() {
  // Важно: listeners должны быть зарегистрированы ДО вызова register().
  // addListener возвращает Promise — без await событие registration может прийти до готовности listener.
  // См. https://github.com/ionic-team/capacitor-plugins/issues/2242
  await PushNotifications.addListener('registration', async (token) => {
    const api = currentApiRef;
    if (api && token?.value) {
      try {
        const deviceId = await TokenStorageService.getOrCreateDeviceId();
        const platform = Capacitor.getPlatform?.() || 'android';
        await api.request('register_push_token', {
          fcm_token: token.value,
          device_id: deviceId,
          platform
        }, 'POST');
      } catch (e) {
        if (process.env.NODE_ENV !== 'production') {
          console.warn('[Push] Failed to send token:', e?.message);
        }
      }
    }
  });

  await PushNotifications.addListener('registrationError', () => {});

  await PushNotifications.addListener('pushNotificationReceived', (notification) => {
    if (process.env.NODE_ENV !== 'production') {
      console.log('[Push] Received:', notification.title, notification.body);
    }
  });

  await PushNotifications.addListener('pushNotificationActionPerformed', (action) => {
    const data = action?.notification?.data || {};
    const type = data.type || '';
    const link = data.link || '';
    if (process.env.NODE_ENV !== 'production') {
      console.log('[Push] Action:', type, link);
    }
    if (typeof window !== 'undefined' && window.location) {
      if (link && link.startsWith('/')) {
        window.location.href = link;
      } else if (type === 'chat') {
        window.location.href = '/chat';
      } else if (type === 'workout' && data.date) {
        window.location.href = `/calendar?date=${encodeURIComponent(data.date)}`;
      }
    }
  });
}
