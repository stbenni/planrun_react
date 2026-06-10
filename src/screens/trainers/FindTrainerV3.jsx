import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import useAuthStore from '../../stores/useAuthStore';
import { CoachAvatar } from '../../components/Coach/CoachPrimitives';
import { getDisplayName } from '../../utils/displayName';
import { GraduationCapIcon } from '../../components/common/Icons';
import './FindTrainerV3.css';

const SPEC_LABELS = {
  marathon: 'Марафон', half_marathon: 'Полумарафон', '5k_10k': '5К / 10К',
  ultra: 'Ультра', trail: 'Трейл', beginner: 'Новичкам',
  injury_recovery: 'Травмы', nutrition: 'Питание', mental: 'Ментальные',
  health: 'Здоровье', speed: 'Скорость',
};

const FILTERS = ['all', 'marathon', 'half_marathon', '5k_10k', 'beginner', 'health'];

const CURRENCY = { rub: '₽', usd: '$', eur: '€' };

function minPrice(pricing) {
  const vals = (pricing || []).map((p) => p.price).filter((p) => p != null && p > 0);
  if (!vals.length) return null;
  const min = Math.min(...vals);
  const cur = CURRENCY[(pricing.find((p) => p.price === min)?.currency || 'rub').toLowerCase()] || '₽';
  return `${min.toLocaleString('ru')} ${cur}`;
}

export default function FindTrainerV3({ onPick }) {
  const navigate = useNavigate();
  const { api } = useAuthStore();
  const [coaches, setCoaches] = useState([]);
  const [loading, setLoading] = useState(true);
  const [spec, setSpec] = useState('all');
  const [acceptingOnly, setAcceptingOnly] = useState(false);

  useEffect(() => {
    if (!api) return;
    let cancelled = false;
    setLoading(true);
    api.listCoaches({ limit: 50 })
      .then((res) => {
        if (cancelled) return;
        setCoaches(res?.data?.coaches || res?.coaches || []);
      })
      .catch(() => { if (!cancelled) setCoaches([]); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [api]);

  const filtered = useMemo(() => coaches.filter((t) => {
    if (acceptingOnly && !t.coach_accepts) return false;
    if (spec !== 'all' && !(t.coach_specialization || []).includes(spec)) return false;
    return true;
  }), [coaches, spec, acceptingOnly]);

  const pick = (t) => { if (onPick) onPick(t); else navigate(`/${t.username_slug || t.username}`); };

  return (
    <div className="ftv3">
      <div className="ftv3__head">
        <h1 className="ftv3__title">Выбрать тренера</h1>
        <p className="ftv3__sub">Персональный план, обратная связь и поддержка живого тренера</p>
      </div>

      <div className="ftv3__filters">
        {FILTERS.map((s) => (
          <button
            key={s}
            type="button"
            className={`ftv3__chip ${spec === s ? 'is-active' : ''}`}
            onClick={() => setSpec(s)}
          >
            {s === 'all' ? 'Все' : (SPEC_LABELS[s] || s)}
          </button>
        ))}
      </div>

      <div className="ftv3__control">
        <button type="button" className="ftv3__toggle" onClick={() => setAcceptingOnly((v) => !v)}>
          <span className={`ftv3__switch ${acceptingOnly ? 'is-on' : ''}`}><span className="ftv3__knob" /></span>
          Только принимающие
        </button>
        <span className="ftv3__count">{filtered.length}&nbsp;{trainersWord(filtered.length)}</span>
      </div>

      {loading ? (
        <div className="ftv3__state">Загрузка…</div>
      ) : filtered.length === 0 ? (
        <div className="ftv3__state">
          <GraduationCapIcon size={40} strokeWidth={1.5} />
          <p>Тренеров по этому фильтру нет</p>
        </div>
      ) : (
        <div className="ftv3__list">
          {filtered.map((t) => {
            const name = getDisplayName(t);
            const price = t.coach_prices_on_request ? 'по запросу' : minPrice(t.pricing);
            const exp = t.coach_experience_years;
            const specs = (t.coach_specialization || []).slice(0, 3);
            return (
              <div key={t.id} role="button" tabIndex={0} className="ftv3__card"
                onClick={() => pick(t)}
                onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(t); } }}>
                <div className="ftv3__card-top">
                  <div className="ftv3__ava">
                    <CoachAvatar athlete={t} size={52} radius={16} apiBaseUrl={api?.baseUrl || '/api'} />
                    {t.coach_accepts && <span className="ftv3__online" />}
                  </div>
                  <div className="ftv3__card-main">
                    <div className="ftv3__name">{name}</div>
                    {exp ? <div className="ftv3__role">{exp}&nbsp;{yearsWord(exp)} опыта</div> : null}
                    <div className="ftv3__stats">
                      {exp ? <Stat label="ОПЫТ" value={exp} sub={yearsWord(exp)} /> : null}
                      <Stat label="АТЛЕТЫ" value={t.athletes_count ?? 0} sub="чел." />
                    </div>
                  </div>
                </div>

                {t.coach_bio && <div className="ftv3__bio">{t.coach_bio}</div>}

                <div className="ftv3__card-foot">
                  <div className="ftv3__tags">
                    {specs.map((s) => <span key={s} className="ftv3__tag">{SPEC_LABELS[s] || s}</span>)}
                  </div>
                  {price && (
                    <div className="ftv3__price">
                      <span className="ftv3__price-val">{price}</span>
                      {!t.coach_prices_on_request && <span className="ftv3__price-sub">/мес</span>}
                    </div>
                  )}
                </div>

                {!t.coach_accepts && (
                  <div className="ftv3__banner">Сейчас не берёт новых учеников</div>
                )}
              </div>
            );
          })}
        </div>
      )}

      <button type="button" className="ftv3__become" onClick={() => navigate('/trainers/apply')}>
        Сами тренируете? <b>Стать тренером →</b>
      </button>
    </div>
  );
}

function Stat({ label, value, sub }) {
  return (
    <div className="ftv3__stat">
      <div className="ftv3__stat-label">{label}</div>
      <div className="ftv3__stat-row">
        <span className="ftv3__stat-val">{value}</span>
        {sub && <span className="ftv3__stat-sub">{sub}</span>}
      </div>
    </div>
  );
}

function yearsWord(n) {
  const a = Math.abs(n) % 100; const b = a % 10;
  if (a > 10 && a < 20) return 'лет';
  if (b > 1 && b < 5) return 'года';
  if (b === 1) return 'год';
  return 'лет';
}

function trainersWord(n) {
  const a = Math.abs(n) % 100; const b = a % 10;
  if (a > 10 && a < 20) return 'тренеров';
  if (b > 1 && b < 5) return 'тренера';
  if (b === 1) return 'тренер';
  return 'тренеров';
}
