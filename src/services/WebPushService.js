function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; i += 1) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}

class WebPushService {
  static isSupported() {
    return typeof window !== 'undefined'
      && typeof navigator !== 'undefined'
      && 'serviceWorker' in navigator
      && 'PushManager' in window
      && 'Notification' in window;
  }

  static getPermission() {
    if (!this.isSupported()) {
      return 'unsupported';
    }
    return window.Notification.permission;
  }

  static async registerServiceWorker() {
    if (!this.isSupported()) {
      return null;
    }
    await navigator.serviceWorker.register('/sw.js');
    return navigator.serviceWorker.ready;
  }

  static async ensureSubscription({ api, csrfToken, vapidPublicKey }) {
    if (!this.isSupported()) {
      throw new Error('Браузер не поддерживает web push');
    }
    if (!api) {
      throw new Error('API не инициализирован');
    }
    if (!csrfToken) {
      throw new Error('Отсутствует CSRF токен');
    }
    if (!vapidPublicKey) {
      throw new Error('На сервере не настроен публичный VAPID ключ');
    }
    if (window.Notification.permission !== 'granted') {
      throw new Error('Разрешение на уведомления не выдано');
    }

    const registration = await this.registerServiceWorker();
    if (!registration) {
      throw new Error('Не удалось зарегистрировать service worker');
    }

    let subscription = await registration.pushManager.getSubscription();
    if (!subscription) {
      subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
      });
    }

    await api.request('register_web_push_subscription', {
      csrf_token: csrfToken,
      subscription: subscription.toJSON(),
      user_agent: navigator.userAgent || '',
    }, 'POST');

    return subscription;
  }

  static async getCurrentSubscription() {
    if (!this.isSupported()) {
      return null;
    }
    const registration = await navigator.serviceWorker.getRegistration('/sw.js')
      || await navigator.serviceWorker.getRegistration()
      || await this.registerServiceWorker();
    if (!registration) {
      return null;
    }
    return registration.pushManager.getSubscription();
  }

  static async unregister({ api, csrfToken }) {
    if (!this.isSupported() || !api || !csrfToken) {
      return false;
    }

    const subscription = await this.getCurrentSubscription();
    const endpoint = subscription?.endpoint || null;
    if (!endpoint) {
      return false;
    }

    try {
      await subscription.unsubscribe();
    } catch (_) {
      // ignore unsubscribe errors and still cleanup server-side record
    }

    await api.request('unregister_web_push_subscription', {
      csrf_token: csrfToken,
      endpoint,
    }, 'POST');

    return true;
  }
}

export default WebPushService;
