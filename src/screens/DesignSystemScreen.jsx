/**
 * Design System Screen — живая документация бренда.
 * Видит все токены, кнопки, карточки, цвета, типографику и т.п. — собрано
 * на основе src/styles/sports-colors.css, buttons.css, cards.css.
 *
 * Admin-only: на главных табах не висит, открывается прямым URL /design-system.
 */

import React, { useState, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import useAuthStore from '../stores/useAuthStore';
import {
  CheckIcon,
  CalendarIcon,
  RestIcon,
  RunningIcon,
  TimeIcon,
  HeartIcon,
  TrendingUpIcon,
  AlertTriangleIcon,
  CloseIcon,
  SettingsIcon,
  TrashIcon,
  PenLineIcon,
  TargetIcon,
  TrophyIcon,
  FlameIcon,
  ZapIcon,
  BellIcon,
  MessageCircleIcon,
  BarChartIcon,
} from '../components/common/Icons';
import './DesignSystemScreen.css';

// ───────────────────────────────────────────────────────────────────────
// Цветовая палитра — все шкалы из sports-colors.css
// ───────────────────────────────────────────────────────────────────────

const PRIMARY_SCALE = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900];
const GRAY_SCALE = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900];

const SEMANTIC_TOKENS = [
  { name: '--success-500', label: 'Success', hint: 'выполнено, легкий бег' },
  { name: '--warning-500', label: 'Warning', hint: 'темповая, внимание' },
  { name: '--accent-500', label: 'Accent / Danger', hint: 'интервалы, ОФП, ошибка' },
  { name: '--info-500', label: 'Info', hint: 'длительная, ссылки' },
];

const WORKOUT_COLORS = [
  { token: '--workout-easy', label: 'Лёгкий бег' },
  { token: '--workout-tempo', label: 'Темповая' },
  { token: '--workout-interval', label: 'Интервалы' },
  { token: '--workout-long', label: 'Длительная' },
  { token: '--workout-control', label: 'Контрольная' },
  { token: '--workout-rest', label: 'Отдых' },
];

const WORKOUT_STRIPS = [
  { token: '--workout-strip-run', label: 'Бег' },
  { token: '--workout-strip-walking', label: 'Ходьба' },
  { token: '--workout-strip-hiking', label: 'Поход' },
  { token: '--workout-strip-sbu', label: 'СБУ' },
  { token: '--workout-strip-ofp', label: 'ОФП' },
];

// ───────────────────────────────────────────────────────────────────────
// Шкалы spacing / radii / type / weights
// ───────────────────────────────────────────────────────────────────────

const SPACING_SCALE = [1, 2, 3, 4, 5, 6, 8, 10, 12, 16];
const RADII = ['sm', 'md', 'lg', 'xl', '2xl', 'full'];
const SHADOWS = ['sm', 'md', 'lg', 'xl', '2xl'];
const TEXT_SCALE = ['xs', 'sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl'];
const FONT_WEIGHTS = [
  { token: '--font-light', weight: 300 },
  { token: '--font-normal', weight: 400 },
  { token: '--font-medium', weight: 500 },
  { token: '--font-semibold', weight: 600 },
  { token: '--font-bold', weight: 700 },
  { token: '--font-extrabold', weight: 800 },
];

// ───────────────────────────────────────────────────────────────────────
// Building blocks — reusable секции
// ───────────────────────────────────────────────────────────────────────

function Section({ id, title, children, subtitle }) {
  return (
    <section className="ds-section" id={id}>
      <header className="ds-section-header">
        <h2 className="ds-section-title">{title}</h2>
        {subtitle && <p className="ds-section-subtitle">{subtitle}</p>}
      </header>
      <div className="ds-section-body">{children}</div>
    </section>
  );
}

function Swatch({ tokenName, fallbackColor, label, sub }) {
  const style = { background: tokenName ? `var(${tokenName})` : fallbackColor };
  return (
    <div className="ds-swatch">
      <div className="ds-swatch__chip" style={style} />
      <div className="ds-swatch__meta">
        <span className="ds-swatch__label">{label}</span>
        {sub && <code className="ds-swatch__sub">{sub}</code>}
      </div>
    </div>
  );
}

function TokenCode({ children }) {
  return <code className="ds-token">{children}</code>;
}

// Демо-вариант WorkoutSheet из mobile UI kit — детальная карточка с интервалами и AI-советом
function WorkoutSheetDemo({ onClose }) {
  const segments = [
    { label: 'Разминка',       dist: '1.5', pace: '5:30/км', color: 'var(--workout-easy)' },
    { label: '1 км в темпе',   dist: '1.0', pace: '4:30/км', color: 'var(--workout-tempo)' },
    { label: 'Восст. трусцой', dist: '0.4', pace: '6:00/км', color: 'var(--workout-easy)' },
    { label: '1 км в темпе',   dist: '1.0', pace: '4:30/км', color: 'var(--workout-tempo)' },
    { label: 'Восст. трусцой', dist: '0.4', pace: '6:00/км', color: 'var(--workout-easy)' },
    { label: '1 км в темпе',   dist: '1.0', pace: '4:30/км', color: 'var(--workout-tempo)' },
    { label: 'Восст. трусцой', dist: '0.4', pace: '6:00/км', color: 'var(--workout-easy)' },
    { label: '1 км в темпе',   dist: '1.0', pace: '4:30/км', color: 'var(--workout-tempo)' },
    { label: 'Заминка',        dist: '1.3', pace: '5:40/км', color: 'var(--workout-easy)' },
  ];

  return (
    <div className="ds-sheet-backdrop" onClick={onClose} role="presentation">
      <div className="ds-sheet" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-labelledby="ds-sheet-title">
        <div className="ds-sheet-grip" aria-hidden />

        <div className="ds-sheet-eyebrow">Вт · 12 мая · темповая</div>
        <h2 id="ds-sheet-title" className="ds-sheet-title">
          4×1 км <span className="ds-sheet-title-accent">в темпе</span>
        </h2>

        <div className="ds-sheet-stats">
          <div className="ds-sheet-stat">
            <div className="ds-sheet-stat__num">8,0</div>
            <div className="ds-sheet-stat__label">км</div>
          </div>
          <div className="ds-sheet-stat ds-sheet-stat--accent">
            <div className="ds-sheet-stat__num">4:30</div>
            <div className="ds-sheet-stat__label">темп /км</div>
          </div>
          <div className="ds-sheet-stat">
            <div className="ds-sheet-stat__num">42</div>
            <div className="ds-sheet-stat__label">мин ~</div>
          </div>
        </div>

        <div className="ds-sheet-section-label">План</div>
        <div className="ds-sheet-bar">
          {segments.map((s, i) => (
            <div key={i} className="ds-sheet-bar__seg" style={{ flex: parseFloat(s.dist), background: s.color }} />
          ))}
        </div>

        <div className="ds-sheet-segments">
          {segments.map((s, i) => (
            <div key={i} className="ds-sheet-seg">
              <span className="ds-sheet-seg__dot" style={{ background: s.color }} />
              <span className="ds-sheet-seg__label">{s.label}</span>
              <span className="ds-sheet-seg__dist">{s.dist} км</span>
              <span className="ds-sheet-seg__pace">{s.pace}</span>
            </div>
          ))}
        </div>

        <div className="ds-sheet-tip">
          <div className="ds-sheet-tip__avatar">AI</div>
          <div className="ds-sheet-tip__text">
            <strong>Совет тренера.</strong> Темповая — про&nbsp;контроль. Старт спокойно,
            держи 4:30 ровно, восстановление в&nbsp;медленном беге, не&nbsp;в&nbsp;шаге.
          </div>
        </div>

        <div className="ds-sheet-actions">
          <button type="button" className="ds-sheet-btn ds-sheet-btn--secondary" onClick={onClose}>
            Перенести
          </button>
          <button type="button" className="ds-sheet-btn ds-sheet-btn--primary">
            <CheckIcon size={16} strokeWidth={2.4} />
            <span>Отметить выполнение</span>
          </button>
        </div>
      </div>
    </div>
  );
}

// ───────────────────────────────────────────────────────────────────────
// Main
// ───────────────────────────────────────────────────────────────────────

export default function DesignSystemScreen() {
  const navigate = useNavigate();
  const { user } = useAuthStore();
  const isAdmin = user?.role === 'admin';
  const [previewLoading, setPreviewLoading] = useState(false);
  const [sheetOpen, setSheetOpen] = useState(false);

  const lucideShowcase = useMemo(() => ([
    { Icon: CheckIcon, name: 'CheckIcon' },
    { Icon: CalendarIcon, name: 'CalendarIcon' },
    { Icon: RestIcon, name: 'RestIcon' },
    { Icon: RunningIcon, name: 'RunningIcon' },
    { Icon: TimeIcon, name: 'TimeIcon' },
    { Icon: HeartIcon, name: 'HeartIcon' },
    { Icon: TrendingUpIcon, name: 'TrendingUpIcon' },
    { Icon: AlertTriangleIcon, name: 'AlertTriangleIcon' },
    { Icon: CloseIcon, name: 'CloseIcon' },
    { Icon: SettingsIcon, name: 'SettingsIcon' },
    { Icon: PenLineIcon, name: 'PenLineIcon' },
    { Icon: TargetIcon, name: 'TargetIcon' },
    { Icon: TrophyIcon, name: 'TrophyIcon' },
    { Icon: FlameIcon, name: 'FlameIcon' },
    { Icon: ZapIcon, name: 'ZapIcon' },
    { Icon: BellIcon, name: 'BellIcon' },
    { Icon: MessageCircleIcon, name: 'MessageCircleIcon' },
    { Icon: BarChartIcon, name: 'BarChartIcon' },
    { Icon: TrashIcon, name: 'TrashIcon' },
  ]), []);

  if (!isAdmin) {
    return (
      <div className="ds-screen ds-screen--locked">
        <div className="ds-locked-card">
          <h1>Только для администраторов</h1>
          <p>Эта страница — внутренняя документация дизайн-системы PlanRun.</p>
          <button className="btn btn-primary" onClick={() => navigate('/')}>На главную</button>
        </div>
      </div>
    );
  }

  return (
    <div className="ds-screen">
      <header className="ds-header">
        <button className="ds-back" onClick={() => navigate('/')} aria-label="Назад">
          ←
        </button>
        <div>
          <p className="ds-kicker">UI KIT · ВНУТРЕННЕЕ</p>
          <h1 className="ds-title">
            <span className="ds-logo-plan">plan</span><span className="ds-logo-run">RUN</span>
            <span className="ds-title-sep"> · </span>
            <span className="ds-title-tail">Design System</span>
          </h1>
          <p className="ds-subtitle">
            Strava-orange + Nike Run Club dark + Apple Liquid Glass.
            Все компоненты и токены проекта собраны на этой странице.
          </p>
        </div>
      </header>

      <nav className="ds-toc">
        {[
          ['colors', 'Цвета'],
          ['type', 'Типографика'],
          ['spacing', 'Отступы'],
          ['radii', 'Скругления'],
          ['shadows', 'Тени'],
          ['buttons', 'Кнопки'],
          ['cards', 'Карточки'],
          ['pills', 'Пилюли'],
          ['workout', 'Workout'],
          ['sheet', 'Детали тренировки'],
          ['icons', 'Иконки'],
          ['anim', 'Анимация'],
        ].map(([href, label]) => (
          <a key={href} className="ds-toc-link" href={`#${href}`}>{label}</a>
        ))}
      </nav>

      {/* ─── Цвета ──────────────────────────────────────────────────── */}
      <Section id="colors" title="Цвета" subtitle="Бренд-палитра + семантика + типы тренировок">
        <h3 className="ds-h3">Primary — Strava Orange</h3>
        <div className="ds-grid ds-grid--10">
          {PRIMARY_SCALE.map(step => (
            <Swatch
              key={step}
              tokenName={`--primary-${step}`}
              label={String(step)}
              sub={`--primary-${step}`}
            />
          ))}
        </div>

        <h3 className="ds-h3">Семантические</h3>
        <div className="ds-grid ds-grid--4">
          {SEMANTIC_TOKENS.map(t => (
            <Swatch key={t.name} tokenName={t.name} label={t.label} sub={t.hint} />
          ))}
        </div>

        <h3 className="ds-h3">Gray scale</h3>
        <div className="ds-grid ds-grid--10">
          {GRAY_SCALE.map(step => (
            <Swatch
              key={step}
              tokenName={`--gray-${step}`}
              label={String(step)}
              sub={`--gray-${step}`}
            />
          ))}
        </div>

        <h3 className="ds-h3">Типы тренировок</h3>
        <div className="ds-grid ds-grid--6">
          {WORKOUT_COLORS.map(w => (
            <Swatch key={w.token} tokenName={w.token} label={w.label} sub={w.token} />
          ))}
        </div>

        <h3 className="ds-h3">Workout strips (4–5px полоска на карточке)</h3>
        <div className="ds-grid ds-grid--5">
          {WORKOUT_STRIPS.map(w => (
            <Swatch key={w.token} tokenName={w.token} label={w.label} sub={w.token} />
          ))}
        </div>
      </Section>

      {/* ─── Типографика ────────────────────────────────────────────── */}
      <Section id="type" title="Типографика" subtitle="Montserrat для UI, Jost для табулярных метрик">
        <h3 className="ds-h3">Шрифты</h3>
        <div className="ds-type-fonts">
          <div className="ds-type-font-card">
            <div className="ds-type-font-card__big" style={{ fontFamily: 'Montserrat' }}>Аа Бб</div>
            <div className="ds-type-font-card__meta">
              <strong>Montserrat</strong>
              <span>300, 400, 500, 600, 700, 800 + italic 800</span>
              <span>UI, заголовки, body</span>
            </div>
          </div>
          <div className="ds-type-font-card">
            <div className="ds-type-font-card__big" style={{ fontFamily: 'Jost', fontWeight: 800, fontStyle: 'italic' }}>32.5</div>
            <div className="ds-type-font-card__meta">
              <strong>Jost</strong>
              <span>tabular-nums, 300–800</span>
              <span>метрики, цифры, статистика</span>
            </div>
          </div>
        </div>

        <h3 className="ds-h3">Шкала размеров</h3>
        <div className="ds-type-scale">
          {TEXT_SCALE.map(size => (
            <div key={size} className="ds-type-scale-row">
              <span className="ds-type-scale-token">--text-{size}</span>
              <span className="ds-type-scale-sample" style={{ fontSize: `var(--text-${size})` }}>
                Тренируйся с AI
              </span>
            </div>
          ))}
        </div>

        <h3 className="ds-h3">Веса</h3>
        <div className="ds-type-weights">
          {FONT_WEIGHTS.map(w => (
            <div key={w.token} className="ds-type-weights-row">
              <span className="ds-type-scale-token">{w.token}</span>
              <span className="ds-type-weights-sample" style={{ fontWeight: w.weight }}>
                Tренируйся с AI · {w.weight}
              </span>
            </div>
          ))}
        </div>

        <h3 className="ds-h3">Заголовки</h3>
        <div className="ds-type-headings">
          <h1 className="ds-h1-demo">Тренируйся с AI или выбери тренера</h1>
          <h2 className="ds-h2-demo">12 недель до марафона</h2>
          <h3 className="ds-h3-demo">Подготовка к 10K</h3>
          <h4 className="ds-h4-demo">Длительная · 18 км</h4>
          <p className="ds-body-demo">
            Зона 2 · 5:24/км · 1 час 38 минут. После тренировки запиши самочувствие
            и темп — AI-тренер скорректирует следующую тренировку.
          </p>
          <p className="ds-caption-demo">Опубликовано 11 мая 2026</p>
        </div>
      </Section>

      {/* ─── Spacing ────────────────────────────────────────────────── */}
      <Section id="spacing" title="Отступы" subtitle="8px-base scale">
        <div className="ds-spacing">
          {SPACING_SCALE.map(n => (
            <div key={n} className="ds-spacing-row">
              <span className="ds-token">--space-{n}</span>
              <div className="ds-spacing-bar" style={{ width: `var(--space-${n})` }} />
              <span className="ds-spacing-size">{n * 4}px</span>
            </div>
          ))}
        </div>
      </Section>

      {/* ─── Радиусы ────────────────────────────────────────────────── */}
      <Section id="radii" title="Скругления" subtitle="2xl (24px) — signature радиус карточек">
        <div className="ds-grid ds-grid--6">
          {RADII.map(r => (
            <div key={r} className="ds-radius-cell">
              <div className="ds-radius-shape" style={{ borderRadius: `var(--radius-${r})` }} />
              <TokenCode>--radius-{r}</TokenCode>
            </div>
          ))}
        </div>
      </Section>

      {/* ─── Тени ───────────────────────────────────────────────────── */}
      <Section id="shadows" title="Тени" subtitle="Все с warm-orange tint, многослойные">
        <div className="ds-grid ds-grid--5">
          {SHADOWS.map(s => (
            <div key={s} className="ds-shadow-cell">
              <div className="ds-shadow-box" style={{ boxShadow: `var(--shadow-${s})` }} />
              <TokenCode>--shadow-{s}</TokenCode>
            </div>
          ))}
        </div>
      </Section>

      {/* ─── Кнопки ─────────────────────────────────────────────────── */}
      <Section id="buttons" title="Кнопки" subtitle=".btn + .btn-primary / .btn-secondary, размеры sm / lg / block">
        <h3 className="ds-h3">Базовые</h3>
        <div className="ds-button-row">
          <button className="btn btn-primary">Primary</button>
          <button className="btn btn-secondary">Secondary</button>
          <button className="btn btn-primary" disabled>Disabled</button>
        </div>

        <h3 className="ds-h3">Размеры</h3>
        <div className="ds-button-row">
          <button className="btn btn-primary btn--sm">Small</button>
          <button className="btn btn-primary">Default</button>
          <button className="btn btn-primary btn--lg">Large</button>
        </div>

        <h3 className="ds-h3">Loading</h3>
        <div className="ds-button-row">
          <button
            className="btn btn-primary"
            onClick={() => { setPreviewLoading(true); setTimeout(() => setPreviewLoading(false), 1500); }}
            disabled={previewLoading}
          >
            {previewLoading && <span className="btn-spinner" />}
            {previewLoading ? 'Загрузка...' : 'Тык'}
          </button>
        </div>

        <h3 className="ds-h3">Block (full-width)</h3>
        <div className="ds-button-block-wrap">
          <button className="btn btn-primary btn--block">Block button</button>
        </div>
      </Section>

      {/* ─── Карточки ───────────────────────────────────────────────── */}
      <Section id="cards" title="Карточки" subtitle=".card / .card--compact / .card--interactive">
        <div className="ds-grid ds-grid--3">
          <div className="card">
            <h4 className="ds-h4-demo">Default card</h4>
            <p className="ds-body-demo">24px radius, warm-orange shadow, glass background, ::before overlay.</p>
          </div>
          <div className="card card--compact">
            <h4 className="ds-h4-demo">Compact card</h4>
            <p className="ds-body-demo">Меньший radius и shadow, для компактных метрик.</p>
          </div>
          <div className="card card--interactive" tabIndex={0}>
            <h4 className="ds-h4-demo">Interactive card</h4>
            <p className="ds-body-demo">Hover → translateY(-2px) + warm border. Кликабельная.</p>
          </div>
        </div>
      </Section>

      {/* ─── Пилюли ────────────────────────────────────────────────── */}
      <Section id="pills" title="Пилюли (Pills)" subtitle="Бейджи статуса, причины, теги">
        <div className="ds-pill-row">
          <span className="ds-demo-pill ds-demo-pill--primary">Сегодня</span>
          <span className="ds-demo-pill ds-demo-pill--success">Выполнено</span>
          <span className="ds-demo-pill ds-demo-pill--warning">Темповая</span>
          <span className="ds-demo-pill ds-demo-pill--danger">Пропущено</span>
          <span className="ds-demo-pill ds-demo-pill--neutral">Отдых</span>
        </div>
      </Section>

      {/* ─── Workout-card preview ───────────────────────────────────── */}
      <Section id="workout" title="Workout card (из дизайн-kit)" subtitle="4–5px цветной strip + eyebrow + meta + action">
        <div className="ds-workout-list">
          <div className="ds-wcard">
            <div className="ds-wcard-strip" style={{ background: 'var(--workout-easy)' }} />
            <div className="ds-wcard-body">
              <div className="ds-wcard-eyebrow">Пн · 11 мая</div>
              <div className="ds-wcard-title">Лёгкая · 6 км</div>
              <div className="ds-wcard-meta">зона 1 · 6:00/км</div>
            </div>
            <div className="ds-wcard-action">
              <div className="ds-wcard-done"><CheckIcon size={16} strokeWidth={3} /></div>
            </div>
          </div>
          <div className="ds-wcard ds-wcard--today">
            <div className="ds-wcard-strip" style={{ background: 'var(--workout-tempo)' }} />
            <div className="ds-wcard-body">
              <div className="ds-wcard-eyebrow">Вт · 12 мая · сегодня</div>
              <div className="ds-wcard-title">Темповая · 8 км</div>
              <div className="ds-wcard-meta">4×1 км · 4:30/км</div>
            </div>
            <div className="ds-wcard-action">
              <button className="ds-wcard-mark">Отметить</button>
            </div>
          </div>
          <div className="ds-wcard">
            <div className="ds-wcard-strip" style={{ background: 'var(--workout-long)' }} />
            <div className="ds-wcard-body">
              <div className="ds-wcard-eyebrow">Вс · 17 мая</div>
              <div className="ds-wcard-title">Длительная · 18 км</div>
              <div className="ds-wcard-meta">зона 2 · 5:24/км</div>
            </div>
            <div className="ds-wcard-action">
              <span className="ds-wcard-chevron" aria-hidden>›</span>
            </div>
          </div>
        </div>
      </Section>

      {/* ─── Sheet с деталями тренировки ───────────────────────────── */}
      <Section
        id="sheet"
        title="Карточка деталей тренировки"
        subtitle="Bottom-sheet с интервалами + AI-совет тренера. Открывается тапом по карточке тренировки."
      >
        <p className="ds-body-demo">
          Заголовок-цель в italic-bold с акцентным фрагментом в оранжевом, 3 stat-карточки
          (км / темп / время), сегментированный progress bar разноцветный по типам, список
          интервалов в Jost tabular-nums, AI-tip как отдельная карточка, action-кнопки внизу.
        </p>
        <div>
          <button className="btn btn-primary" onClick={() => setSheetOpen(true)}>
            Открыть пример
          </button>
        </div>
      </Section>

      {sheetOpen && <WorkoutSheetDemo onClose={() => setSheetOpen(false)} />}

      {/* ─── Иконки ────────────────────────────────────────────────── */}
      <Section id="icons" title="Иконки" subtitle="Lucide-react: stroke 1.5–1.8, currentColor">
        <div className="ds-icon-grid">
          {lucideShowcase.map(({ Icon, name }) => (
            <div key={name} className="ds-icon-cell">
              <Icon size={24} />
              <code>{name}</code>
            </div>
          ))}
        </div>
      </Section>

      {/* ─── Анимация ──────────────────────────────────────────────── */}
      <Section id="anim" title="Анимация" subtitle="Transitions, spinner, hover-lift">
        <div className="ds-grid ds-grid--3">
          <div className="ds-anim-cell">
            <div className="ds-spinner-demo" />
            <code>btn-spinner</code>
          </div>
          <div className="ds-anim-cell">
            <div className="ds-hover-lift-demo">Hover</div>
            <code>translateY(-2px) + shadow</code>
          </div>
          <div className="ds-anim-cell">
            <div className="ds-pulse-demo" />
            <code>app-update-spin keyframe</code>
          </div>
        </div>
        <p className="ds-body-demo" style={{ marginTop: 16 }}>
          Easing: <TokenCode>cubic-bezier(0.4, 0, 0.2, 1)</TokenCode> для UI,
          {' '}<TokenCode>cubic-bezier(0.34, 1.56, 0.64, 1)</TokenCode> для модалок (bouncy).
          Durations: <TokenCode>--transition-fast</TokenCode> 150ms,
          {' '}<TokenCode>--transition-base</TokenCode> 200ms,
          {' '}<TokenCode>--transition-slow</TokenCode> 300ms.
        </p>
      </Section>

      <footer className="ds-footer">
        <p>Дизайн-система живая. При изменении токенов в <TokenCode>src/styles/sports-colors.css</TokenCode> эта страница автоматически обновляется.</p>
      </footer>
    </div>
  );
}
