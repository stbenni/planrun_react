import { useEffect, useRef, useState } from 'react';
import { useSwipeableTabs } from '../../../hooks/useSwipeableTabs';
import OverviewTabV3 from './OverviewTabV3';
import RecordsTabV3 from './RecordsTabV3';
import TrendsTabV3 from './TrendsTabV3';
import AchievementsTabV3 from './AchievementsTabV3';
import StatsDesktopV3 from './StatsDesktopV3';
import './StatsV3.css';

const TABS = [
  ['overview', 'Обзор'],
  ['records', 'Рекорды'],
  ['trends', 'Тренды'],
  ['ach', 'Достижения'],
];
const TAB_IDS = TABS.map(([id]) => id);

function useIsDesktop() {
  const q = '(min-width: 1024px)';
  const [m, setM] = useState(() => (typeof window !== 'undefined' && window.matchMedia
    ? window.matchMedia(q).matches : false));
  useEffect(() => {
    if (typeof window === 'undefined' || !window.matchMedia) return undefined;
    const mq = window.matchMedia(q);
    const fn = () => setM(mq.matches);
    mq.addEventListener('change', fn);
    fn();
    return () => mq.removeEventListener('change', fn);
  }, []);
  return m;
}

export default function StatsV3({
  api, viewContext = null, rawData, user, onWorkoutClick, initialTab = 'overview',
}) {
  const isDesktop = useIsDesktop();
  const [tab, setTab] = useState(TAB_IDS.includes(initialTab) ? initialTab : 'overview');
  const [sport, setSport] = useState('all');
  const [period, setPeriod] = useState('month');
  const panelsRef = useRef(null);

  useSwipeableTabs({
    containerRef: panelsRef,
    tabs: TAB_IDS,
    activeTab: tab,
    onTabChange: setTab,
    enabled: !isDesktop,
    ignoreSelector: '.heatmap-months-container, [data-swipe-lock="true"], input, textarea, select, [contenteditable="true"]',
  });

  if (isDesktop) {
    return (
      <StatsDesktopV3
        api={api}
        viewContext={viewContext}
        rawData={rawData}
        user={user}
        sport={sport}
        setSport={setSport}
        period={period}
        setPeriod={setPeriod}
        onWorkoutClick={onWorkoutClick}
      />
    );
  }

  return (
    <div className="statv3">
      <h1 className="statv3-title">Статистика</h1>

      <div className="statv3-tabs-wrap">
        <div className="statv3-tabs" role="tablist" aria-label="Разделы статистики">
          {TABS.map(([id, label]) => (
            <button
              key={id}
              type="button"
              role="tab"
              aria-selected={tab === id}
              className={`statv3-tab ${tab === id ? 'is-active' : ''}`}
              onClick={() => setTab(id)}
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      <div ref={panelsRef} className="statv3-panels">
        {tab === 'overview' && (
          <OverviewTabV3
            rawData={rawData}
            sport={sport}
            setSport={setSport}
            period={period}
            setPeriod={setPeriod}
            onWorkoutClick={onWorkoutClick}
          />
        )}
        {tab === 'records' && <RecordsTabV3 api={api} viewContext={viewContext} />}
        {tab === 'trends' && <TrendsTabV3 api={api} viewContext={viewContext} rawData={rawData} />}
        {tab === 'ach' && <AchievementsTabV3 api={api} viewContext={viewContext} rawData={rawData} />}
      </div>
    </div>
  );
}
