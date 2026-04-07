export const NOTIFICATION_CHANNELS = ['mobile_push', 'web_push', 'telegram', 'email'];

const DEFAULT_CATALOG = [
  {
    key: 'workouts',
    label: 'Тренировки',
    description: 'Напоминания о ближайших тренировках',
    events: [
      {
        event_key: 'workout.reminder.today',
        label: 'Сегодняшняя тренировка',
        description: 'Напоминание в день тренировки',
        channels: NOTIFICATION_CHANNELS,
      },
      {
        event_key: 'workout.reminder.tomorrow',
        label: 'Завтрашняя тренировка',
        description: 'Напоминание накануне тренировки',
        channels: NOTIFICATION_CHANNELS,
      },
    ],
  },
  {
    key: 'chat',
    label: 'Чат',
    description: 'Сообщения от пользователей, администрации и ИИ',
    events: [
      {
        event_key: 'chat.admin_message',
        label: 'Сообщение от администрации',
        description: 'Когда администратор пишет вам в чат',
        channels: NOTIFICATION_CHANNELS,
      },
      {
        event_key: 'chat.direct_message',
        label: 'Сообщение от пользователя',
        description: 'Когда другой пользователь пишет вам напрямую',
        channels: NOTIFICATION_CHANNELS,
      },
      {
        event_key: 'chat.ai_message',
        label: 'Сообщение от ИИ-тренера',
        description: 'Когда ИИ присылает новый ответ вне открытого чата',
        channels: NOTIFICATION_CHANNELS,
      },
      {
        event_key: 'admin.new_user_message',
        label: 'Новое сообщение в чате администрации',
        description: 'Когда пользователь пишет администрации',
        channels: NOTIFICATION_CHANNELS,
        roles: ['admin'],
      },
    ],
  },
  {
    key: 'plan',
    label: 'План и адаптация',
    description: 'Изменения плана, заметки и обзоры ИИ',
    events: [
      {
        event_key: 'plan.coach_updated',
        label: 'Тренер обновил план',
        description: 'Изменение тренировки или копирование плана',
        channels: NOTIFICATION_CHANNELS,
      },
      {
        event_key: 'plan.coach_note_added',
        label: 'Тренер оставил заметку',
        description: 'Новая заметка к дню или неделе',
        channels: NOTIFICATION_CHANNELS,
      },
      {
        event_key: 'coach.athlete_result_logged',
        label: 'Атлет внёс результат',
        description: 'Результат тренировки, доступный тренеру',
        channels: NOTIFICATION_CHANNELS,
        roles: ['coach', 'admin'],
      },
      {
        event_key: 'plan.weekly_review',
        label: 'Недельный обзор ИИ',
        description: 'Еженедельное сообщение с разбором плана',
        channels: NOTIFICATION_CHANNELS,
      },
      {
        event_key: 'plan.weekly_adaptation',
        label: 'Недельная адаптация',
        description: 'ИИ адаптировал план на следующую неделю',
        channels: NOTIFICATION_CHANNELS,
      },
      {
        event_key: 'plan.generated',
        label: 'План сгенерирован',
        description: 'Готов новый тренировочный план',
        channels: NOTIFICATION_CHANNELS,
      },
      {
        event_key: 'plan.recalculated',
        label: 'План пересчитан',
        description: 'ИИ завершил пересчёт плана',
        channels: NOTIFICATION_CHANNELS,
      },
      {
        event_key: 'plan.next_generated',
        label: 'Следующий план готов',
        description: 'Сформирован следующий цикл тренировок',
        channels: NOTIFICATION_CHANNELS,
      },
      {
        event_key: 'performance.vdot_updated',
        label: 'Индекс формы (VDOT) обновлён',
        description: 'После контрольной тренировки или забега',
        channels: NOTIFICATION_CHANNELS,
      },
    ],
  },
  {
    key: 'system',
    label: 'Системные',
    description: 'Сервисные письма, которые нельзя отключить',
    events: [
      {
        event_key: 'system.auth_verification_code',
        label: 'Код подтверждения почты',
        description: 'Письмо при регистрации',
        channels: ['email'],
        locked: true,
      },
      {
        event_key: 'system.password_reset',
        label: 'Сброс пароля',
        description: 'Письмо для восстановления доступа',
        channels: ['email'],
        locked: true,
      },
    ],
  },
];

function buildDefaultPreferences() {
  return DEFAULT_CATALOG.reduce((acc, group) => {
    (group.events || []).forEach((event) => {
      acc[event.event_key] = {
        mobile_push_enabled: event.event_key === 'workout.reminder.tomorrow' || event.event_key.startsWith('chat.'),
        web_push_enabled: false,
        telegram_enabled: false,
        email_enabled: Boolean(event.locked),
      };
      if (event.event_key === 'admin.new_user_message') {
        acc[event.event_key].mobile_push_enabled = true;
      }
    });
    return acc;
  }, {});
}

export function createInitialNotificationSettings(timezone = 'Europe/Moscow') {
  return {
    version: 1,
    timezone,
    channels: {
      mobile_push: {
        enabled: true,
        available: false,
        connected_devices: 0,
        delivery_ready: true,
      },
      web_push: {
        enabled: true,
        available: false,
        subscriptions: 0,
        subscription_items: [],
        delivery_ready: false,
      },
      telegram: {
        enabled: true,
        available: false,
        linked: false,
        delivery_ready: true,
      },
      email: {
        enabled: true,
        available: false,
        delivery_ready: true,
        digest_mode: 'instant',
      },
    },
    schedule: {
      workout_today_time: '08:00',
      workout_tomorrow_time: '20:00',
    },
    quiet_hours: {
      enabled: false,
      start: '22:00',
      end: '07:00',
    },
    preferences: buildDefaultPreferences(),
    catalog: DEFAULT_CATALOG,
  };
}

export function ensureNotificationChannelsEnabled(settings) {
  if (!settings || typeof settings !== 'object') {
    return settings;
  }

  return {
    ...settings,
    channels: NOTIFICATION_CHANNELS.reduce((acc, channelKey) => {
      acc[channelKey] = {
        ...(settings.channels?.[channelKey] || {}),
        enabled: true,
      };
      return acc;
    }, { ...(settings.channels || {}) }),
  };
}

function toBoolean(value, fallback = false) {
  if (typeof value === 'boolean') return value;
  if (typeof value === 'number') return value === 1;
  if (typeof value === 'string') {
    const normalized = value.trim().toLowerCase();
    if (['1', 'true', 'yes', 'on'].includes(normalized)) return true;
    if (['0', 'false', 'no', 'off'].includes(normalized)) return false;
  }
  return fallback;
}

function normalizeTime(value, fallback) {
  const input = String(value || '').trim();
  const match = input.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
  if (!match) return fallback;
  const hours = Number(match[1]);
  const minutes = Number(match[2]);
  if (!Number.isFinite(hours) || !Number.isFinite(minutes)) return fallback;
  if (hours < 0 || hours > 23 || minutes < 0 || minutes > 59) return fallback;
  return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
}

export function normalizeNotificationSettings(rawSettings = null, timezone = 'Europe/Moscow') {
  const defaults = createInitialNotificationSettings(timezone);
  const source = rawSettings && typeof rawSettings === 'object' ? rawSettings : {};
  const catalog = Array.isArray(source.catalog) && source.catalog.length > 0 ? source.catalog : defaults.catalog;
  const preferences = { ...defaults.preferences };

  catalog.forEach((group) => {
    (group.events || []).forEach((event) => {
      const sourcePref = source.preferences?.[event.event_key] || {};
      preferences[event.event_key] = {
        mobile_push_enabled: toBoolean(sourcePref.mobile_push_enabled, defaults.preferences[event.event_key]?.mobile_push_enabled ?? false),
        web_push_enabled: toBoolean(sourcePref.web_push_enabled, defaults.preferences[event.event_key]?.web_push_enabled ?? false),
        telegram_enabled: toBoolean(sourcePref.telegram_enabled, defaults.preferences[event.event_key]?.telegram_enabled ?? false),
        email_enabled: event.locked
          ? true
          : toBoolean(sourcePref.email_enabled, defaults.preferences[event.event_key]?.email_enabled ?? false),
      };
    });
  });

  return {
    version: Number(source.version || defaults.version) || defaults.version,
    timezone: String(source.timezone || timezone || defaults.timezone),
    channels: {
      mobile_push: {
        ...defaults.channels.mobile_push,
        ...(source.channels?.mobile_push || {}),
        enabled: toBoolean(source.channels?.mobile_push?.enabled, defaults.channels.mobile_push.enabled),
        available: toBoolean(source.channels?.mobile_push?.available, defaults.channels.mobile_push.available),
        delivery_ready: toBoolean(source.channels?.mobile_push?.delivery_ready, defaults.channels.mobile_push.delivery_ready),
      },
      web_push: {
        ...defaults.channels.web_push,
        ...(source.channels?.web_push || {}),
        enabled: toBoolean(source.channels?.web_push?.enabled, defaults.channels.web_push.enabled),
        available: toBoolean(source.channels?.web_push?.available, defaults.channels.web_push.available),
        delivery_ready: toBoolean(source.channels?.web_push?.delivery_ready, defaults.channels.web_push.delivery_ready),
        subscription_items: Array.isArray(source.channels?.web_push?.subscription_items)
          ? source.channels.web_push.subscription_items
            .filter((item) => item && typeof item === 'object')
            .map((item) => ({
              endpoint: String(item.endpoint || ''),
              user_agent: String(item.user_agent || ''),
              created_at: String(item.created_at || ''),
              last_seen_at: String(item.last_seen_at || ''),
            }))
            .filter((item) => item.endpoint)
          : defaults.channels.web_push.subscription_items,
      },
      telegram: {
        ...defaults.channels.telegram,
        ...(source.channels?.telegram || {}),
        enabled: toBoolean(source.channels?.telegram?.enabled, defaults.channels.telegram.enabled),
        available: toBoolean(source.channels?.telegram?.available, defaults.channels.telegram.available),
        linked: toBoolean(source.channels?.telegram?.linked, defaults.channels.telegram.linked),
        delivery_ready: toBoolean(source.channels?.telegram?.delivery_ready, defaults.channels.telegram.delivery_ready),
      },
      email: {
        ...defaults.channels.email,
        ...(source.channels?.email || {}),
        enabled: toBoolean(source.channels?.email?.enabled, defaults.channels.email.enabled),
        available: toBoolean(source.channels?.email?.available, defaults.channels.email.available),
        delivery_ready: toBoolean(source.channels?.email?.delivery_ready, defaults.channels.email.delivery_ready),
        digest_mode: ['daily', 'instant'].includes(source.channels?.email?.digest_mode)
          ? source.channels.email.digest_mode
          : defaults.channels.email.digest_mode,
      },
    },
    schedule: {
      workout_today_time: normalizeTime(source.schedule?.workout_today_time, defaults.schedule.workout_today_time),
      workout_tomorrow_time: normalizeTime(source.schedule?.workout_tomorrow_time, defaults.schedule.workout_tomorrow_time),
    },
    quiet_hours: {
      enabled: toBoolean(source.quiet_hours?.enabled, defaults.quiet_hours.enabled),
      start: normalizeTime(source.quiet_hours?.start, defaults.quiet_hours.start),
      end: normalizeTime(source.quiet_hours?.end, defaults.quiet_hours.end),
    },
    preferences,
    catalog,
  };
}
