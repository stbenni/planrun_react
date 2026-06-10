import { Group, Row, FieldRow, ToggleRow } from '../primitives';

function Check({ state, onToggle }) {
  if (!state.supportsChannel) return <span className="sv3-matrix-cell"><span className="sv3-check sv3-check--na">—</span></span>;
  return (
    <span className="sv3-matrix-cell">
      <button
        type="button"
        disabled={state.disabled}
        onClick={() => onToggle(!state.checked)}
        className={`sv3-check ${state.checked ? 'sv3-check--on' : 'sv3-check--off'}`}
      >
        ✓
      </button>
    </span>
  );
}

export default function NotifSectionV3({ ctx }) {
  const {
    notificationSettings, availableChannels, channelMeta, visibleNotificationGroups,
    getEventChannelState, onToggleNotification, updatePaused, updateNotificationTime, updateQuietHours,
    webPushSetupState, showWebPushSetup, telegramNotLinked, onResetNotifications, onTestNotification, goToTab,
  } = ctx;

  return (
    <>
      <Group>
        <ToggleRow label="Не беспокоить" sub="Пауза всех уведомлений, кроме обязательных писем"
          on={Boolean(notificationSettings.paused)} onChange={updatePaused} />
      </Group>

      {showWebPushSetup && webPushSetupState && (
        <Group>
          <Row>
            <div className="sv3-row-main">
              <div className="sv3-row-title">Уведомления в браузере</div>
              <div className="sv3-row-sub">{webPushSetupState.summary}</div>
            </div>
            {webPushSetupState.actionLabel && webPushSetupState.action && (
              <button type="button" className="sv3-ghost-btn" disabled={webPushSetupState.actionBusy}
                onClick={webPushSetupState.action}>{webPushSetupState.actionLabel}</button>
            )}
          </Row>
        </Group>
      )}

      <Group label="Расписание">
        <FieldRow label="Утром о тренировке">
          <input type="time" className="sv3-input" value={notificationSettings.schedule?.workout_today_time || '08:00'}
            onChange={(e) => updateNotificationTime('workout_today_time', '08:00', e.target.value)} />
        </FieldRow>
        <FieldRow label="Вечером о завтра">
          <input type="time" className="sv3-input" value={notificationSettings.schedule?.workout_tomorrow_time || '20:00'}
            onChange={(e) => updateNotificationTime('workout_tomorrow_time', '20:00', e.target.value)} />
        </FieldRow>
        <ToggleRow label="Тихие часы" sub="В это время уведомления не приходят"
          on={Boolean(notificationSettings.quiet_hours?.enabled)} onChange={(v) => updateQuietHours('enabled', v)} />
        {notificationSettings.quiet_hours?.enabled && (
          <>
            <FieldRow label="С">
              <input type="time" className="sv3-input" value={notificationSettings.quiet_hours?.start || '22:00'}
                onChange={(e) => updateQuietHours('start', e.target.value || '22:00')} />
            </FieldRow>
            <FieldRow label="До">
              <input type="time" className="sv3-input" value={notificationSettings.quiet_hours?.end || '07:00'}
                onChange={(e) => updateQuietHours('end', e.target.value || '07:00')} />
            </FieldRow>
          </>
        )}
      </Group>

      {visibleNotificationGroups.map((group) => (
        <Group key={group.key} label={group.label}>
          <div className="sv3-matrix-head">
            <span className="sv3-matrix-evt" />
            {availableChannels.map((ch) => (
              <span key={ch} className="sv3-matrix-ch">
                {channelMeta[ch].shortLabel}
                {ch === 'telegram' && telegramNotLinked && (
                  <button type="button" className="sv3-matrix-ch-cta" onClick={() => goToTab('integrations')}>подключить</button>
                )}
              </span>
            ))}
          </div>
          {group.events.map((event) => (
            <div key={event.event_key} className="sv3-matrix-row">
              <div className="sv3-matrix-evt">
                {event.label}
                {event.locked && <em>Обязательно</em>}
              </div>
              {availableChannels.map((ch) => (
                <Check key={`${event.event_key}-${ch}`} state={getEventChannelState(event, ch)}
                  onToggle={(checked) => onToggleNotification(event.event_key, ch, checked)} />
              ))}
            </div>
          ))}
        </Group>
      ))}

      <Group>
        <Row>
          <button type="button" className="sv3-ghost-btn" onClick={onTestNotification}>Отправить тестовое</button>
          <div className="sv3-spacer" />
          <button type="button" className="sv3-link-btn" onClick={onResetNotifications}>Сбросить</button>
        </Row>
      </Group>
    </>
  );
}
