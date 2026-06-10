import { Group, Row, ToggleRow } from '../primitives';

const PROVIDERS = [
  { id: 'strava', name: 'Strava', logo: '/integrations/strava.svg', detail: 'GPS-треки и тренировки', sync: true },
  { id: 'polar', name: 'Polar', logo: '/integrations/polar.svg', detail: 'Импорт тренировок', sync: true },
  { id: 'garmin', name: 'Garmin', logo: '/integrations/garmin.svg', detail: 'Часы и датчики', sync: true },
  { id: 'coros', name: 'COROS', logo: '/integrations/coros.svg', detail: 'Импорт тренировок', sync: true },
  { id: 'suunto', name: 'Suunto', logo: '/integrations/suunto.svg', detail: 'GPS/FIT, авто-синк', sync: true },
  { id: 'huawei', name: 'Huawei Health', logo: '/integrations/huawei.svg', detail: 'Импорт активностей', sync: true },
];

function ProviderRow({ name, logo, letter, detail, connected, syncing, onConnect, onSync, onUnlink, busy }) {
  return (
    <Row className="sv3-id-row">
      <div className="sv3-prov-logo">
        {logo ? <img src={logo} alt={name} /> : <span style={{ fontWeight: 800, fontSize: 13 }}>{letter}</span>}
      </div>
      <div className="sv3-row-main" style={{ marginLeft: 12 }}>
        <div className="sv3-prov-name">
          <span>{name}</span>
          {connected && <span className="sv3-prov-dot" />}
        </div>
        <div className="sv3-prov-detail">{detail}</div>
      </div>
      <div className="sv3-prov-actions">
        {connected ? (
          <>
            {onSync && <button type="button" className="sv3-connect-btn" disabled={syncing || busy} onClick={onSync}>{syncing ? '…' : 'Синхр.'}</button>}
            <button type="button" className="sv3-link-btn" disabled={busy} onClick={onUnlink}>Отключить</button>
          </>
        ) : (
          <button type="button" className="sv3-connect-btn" disabled={busy} onClick={onConnect}>{busy ? '…' : 'Подключить'}</button>
        )}
      </div>
    </Row>
  );
}

export default function IntegrationsSectionV3({ ctx }) {
  const {
    integrationsStatus, syncingFlags, connectProvider, syncProvider, unlinkProvider,
    formData, isTelegramConnecting, onConnectTelegram, onUnlinkTelegram,
    hc, suuntoMirror, onSetSuuntoMirror,
  } = ctx;
  const telegramLinked = Boolean(formData.telegram_id);

  return (
    <>
      <Group label="Устройства и сервисы" footer="Тренировки импортируются автоматически после подключения">
        {PROVIDERS.map((p) => (
          <ProviderRow
            key={p.id}
            name={p.name}
            logo={p.logo}
            detail={p.detail}
            connected={!!integrationsStatus[p.id]}
            syncing={!!syncingFlags?.[p.id]}
            onConnect={() => connectProvider(p.id)}
            onSync={p.sync ? () => syncProvider(p.id) : null}
            onUnlink={() => unlinkProvider(p.id)}
          />
        ))}

        <ProviderRow
          name="Telegram"
          logo="/integrations/telegram.svg"
          detail={telegramLinked ? 'Привязан · уведомления и вход' : 'Уведомления и вход'}
          connected={telegramLinked}
          onConnect={onConnectTelegram}
          onUnlink={onUnlinkTelegram}
          busy={isTelegramConnecting}
        />

        {(hc.available || hc.connected) && (
          <ProviderRow
            name="Health Connect"
            letter="HC"
            detail="Android · агрегатор данных"
            connected={hc.connected}
            syncing={hc.busy}
            onConnect={hc.connect}
            onSync={() => hc.sync()}
            onUnlink={hc.disconnect}
            busy={hc.busy}
          />
        )}
      </Group>

      {suuntoMirror.available && (
        <Group footer="Для тех, кто не может использовать Health Sync (Strava → PlanRun → Suunto).">
          <ToggleRow label="Отправлять мои тренировки в Suunto" on={suuntoMirror.enabled}
            disabled={suuntoMirror.saving} onChange={onSetSuuntoMirror} />
        </Group>
      )}
    </>
  );
}
