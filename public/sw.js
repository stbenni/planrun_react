self.addEventListener('push', (event) => {
  let payload = {};

  try {
    payload = event.data ? event.data.json() : {};
  } catch (_) {
    payload = { body: event.data ? event.data.text() : '' };
  }

  const title = payload.title || 'PlanRun';
  const body = payload.body || 'У вас новое уведомление';
  const data = payload.data || {};
  const icon = payload.icon || '/favicon-96x96.png';
  const badge = payload.badge || '/favicon-96x96.png';

  event.waitUntil(
    self.registration.showNotification(title, {
      body,
      icon,
      badge,
      data,
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const target = event.notification?.data?.link || '/';
  const targetUrl = new URL(target, self.location.origin).href;

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if ('navigate' in client) {
          client.navigate(targetUrl);
        }
        if ('focus' in client) {
          return client.focus();
        }
      }

      if (self.clients.openWindow) {
        return self.clients.openWindow(targetUrl);
      }

      return undefined;
    })
  );
});
