import { Group, Row, FieldRow, ToggleRow } from '../primitives';

export default function SecuritySectionV3({ ctx }) {
  const {
    formData, onField,
    showBiometricSection, pinEnabled, biometricEnabled, biometricAvailable, biometricEnabling, pinDisabling,
    onEnableLock, onDisableLock, onAddFingerprint, onChangePassword, onDeleteAccount, onLogout,
  } = ctx;

  return (
    <>
      <Group label="Вход и доступ">
        <FieldRow label="Email">
          <input type="email" className="sv3-input" placeholder="email@example.com"
            value={formData.email || ''} onChange={(e) => onField('email', e.target.value)} />
        </FieldRow>
        {onChangePassword && (
          <Row onClick={onChangePassword}>
            <span className="sv3-row-main sv3-row-title">Сменить пароль</span>
            <span className="sv3-chev">›</span>
          </Row>
        )}
      </Group>

      {showBiometricSection && (
        <Group label="Блокировка приложения"
          footer={!biometricAvailable && pinEnabled && !biometricEnabled
            ? 'На этом устройстве отпечаток недоступен.'
            : 'PIN обязателен. Отпечаток — для быстрого входа. Хранится в Secure Storage устройства.'}>
          {!pinEnabled ? (
            <Row>
              <div className="sv3-row-main">
                <div className="sv3-row-title">PIN-код</div>
                <div className="sv3-row-sub">Блокировка выключена</div>
              </div>
              <button type="button" className="sv3-connect-btn" onClick={onEnableLock}>Включить</button>
            </Row>
          ) : (
            <>
              <Row>
                <div className="sv3-row-main">
                  <div className="sv3-row-title">PIN-код</div>
                  <div className="sv3-row-sub">{biometricEnabled ? 'Включён (PIN + отпечаток)' : 'Включён'}</div>
                </div>
                <button type="button" className="sv3-link-btn" disabled={pinDisabling} onClick={onDisableLock}>
                  {pinDisabling ? '…' : 'Отключить'}
                </button>
              </Row>
              {!biometricEnabled && biometricAvailable && (
                <Row>
                  <div className="sv3-row-main">
                    <div className="sv3-row-title">Отпечаток пальца</div>
                    <div className="sv3-row-sub">Быстрый вход</div>
                  </div>
                  <button type="button" className="sv3-connect-btn" disabled={biometricEnabling} onClick={onAddFingerprint}>
                    {biometricEnabling ? '…' : 'Добавить'}
                  </button>
                </Row>
              )}
              {biometricEnabled && (
                <ToggleRow label="Отпечаток пальца" sub="Быстрый вход" on disabled />
              )}
            </>
          )}
        </Group>
      )}

      {onDeleteAccount && (
        <Group>
          <Row className="sv3-row-danger" onClick={onDeleteAccount}>
            <span className="sv3-row-main sv3-row-title">Удалить аккаунт</span>
          </Row>
        </Group>
      )}

      <Group>
        <Row onClick={onLogout}>
          <span className="sv3-row-main sv3-row-title" style={{ fontWeight: 600 }}>Выйти из аккаунта</span>
        </Row>
      </Group>
    </>
  );
}
