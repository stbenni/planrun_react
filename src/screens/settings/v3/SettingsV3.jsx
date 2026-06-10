import { useMediaQuery } from '../../../hooks/useMediaQuery';
import { CATS, catById } from './catalog';
import { Group, Row } from './primitives';
import { ChevronIcon, BackIcon } from './icons';
import SettingsDesktopV3 from './SettingsDesktopV3';
import './SettingsV3.css';

function MobileSettingsV3({ ctx }) {
  const { activeCat, setCat, avatarSrc, avatarInitials, formData } = ctx;
  const cat = catById(activeCat);
  const fullName = [formData.first_name, formData.last_name].filter(Boolean).join(' ') || 'Профиль';
  const modeLabel = { ai: 'AI-тренер', coach: 'С тренером', self: 'Самостоятельно' }[formData.training_mode || 'ai'];

  return (
    <div className="sv3 sv3-mob">
      <div className={`sv3-mob-head ${cat ? 'sv3-mob-head--drill' : ''}`}>
        {cat && <button type="button" className="sv3-back" onClick={() => setCat(null)} aria-label="Назад"><BackIcon /></button>}
        <div className="sv3-mob-title">{cat ? cat.title : 'Настройки'}</div>
      </div>

      <div className="sv3-mob-scroll">
        {!cat ? (
          <>
            <Group>
              <Row className="sv3-id-row" onClick={() => setCat('profile')}>
                <div className="sv3-avatar sv3-avatar--sm">
                  {avatarSrc ? <img src={avatarSrc} alt="" className="sv3-avatar sv3-avatar--sm" /> : (avatarInitials || '🙂')}
                </div>
                <div className="sv3-row-main" style={{ marginLeft: 14 }}>
                  <div className="sv3-id-name">{fullName}</div>
                  <div className="sv3-id-sub">@{formData.username || 'username'} · {modeLabel}</div>
                </div>
                <span className="sv3-chev"><ChevronIcon /></span>
              </Row>
            </Group>

            <Group>
              {CATS.map((c) => (
                <Row key={c.id} onClick={() => setCat(c.id)}>
                  <span className="sv3-cat-icon"><c.Icon /></span>
                  <span className="sv3-row-main sv3-cat-title" style={{ marginLeft: 12 }}>{c.title}</span>
                  <span className="sv3-chev"><ChevronIcon /></span>
                </Row>
              ))}
            </Group>
            <div className="sv3-mob-foot" />
          </>
        ) : (
          <>
            <cat.Component ctx={ctx} />
            <div className="sv3-mob-foot" />
          </>
        )}
      </div>
    </div>
  );
}

export default function SettingsV3({ ctx, layout = 'auto' }) {
  const isDesktop = useMediaQuery('(min-width: 1024px)');
  // layout='drill' — всегда одноколоночный drill-in (для выезжающей панели настроек).
  if (layout !== 'drill' && isDesktop) return <SettingsDesktopV3 ctx={ctx} />;
  return <MobileSettingsV3 ctx={ctx} />;
}
