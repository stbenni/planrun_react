import { useEffect, useMemo, useState } from 'react';
import { processOverviewV3, processTrendsV3, processLoadV3 } from '../statsV3Utils';
import { computeAchievements } from '../statsAchievements';
import {
  SportSwitch, PeriodSeg, HeroVolume, MiniCard, RecentList, TrendCard, LoadCard, PrGrid,
} from './blocks';
import StaHexBadge from './StaHexBadge';

export default function StatsDesktopV3({
  api, viewContext = null, rawData, sport, setSport, period, setPeriod, onWorkoutClick,
}) {
  const [load, setLoad] = useState({ available: false });
  const [records, setRecords] = useState(null);
  const [vdot, setVdot] = useState(null);

  useEffect(() => {
    if (!api) return undefined;
    let cancelled = false;
    if (api.getTrainingLoad) {
      api.getTrainingLoad(viewContext, 90)
        .then((res) => { if (!cancelled) setLoad(processLoadV3(res?.data || res)); })
        .catch(() => { if (!cancelled) setLoad({ available: false }); });
    }
    if (api.getRacePrediction) {
      api.getRacePrediction(viewContext)
        .then((res) => { const dp = res?.data || res; if (!cancelled) setVdot(dp?.vdot ?? null); })
        .catch(() => {});
    }
    if (api.getPersonalRecords) {
      api.getPersonalRecords()
        .then((res) => {
          if (cancelled) return;
          const list = res?.data?.records ?? res?.records ?? [];
          const byKey = {};
          list.forEach((r) => { if (r?.distance_label) byKey[r.distance_label] = r; });
          setRecords(byKey);
        })
        .catch(() => { if (!cancelled) setRecords({}); });
    }
    return () => { cancelled = true; };
  }, [api, viewContext]);

  const d = useMemo(() => processOverviewV3(rawData?.workoutsList || [], rawData?.plan, period, sport), [rawData, period, sport]);
  const metrics = useMemo(() => processTrendsV3(rawData?.workoutsList || [], 'run', 12), [rawData]);
  const ach = useMemo(
    () => computeAchievements({ workoutsList: rawData?.workoutsList || [], vdot, records }),
    [rawData, vdot, records],
  );
  const gotBadges = ach.categories.flatMap((c) => c.badges).filter((b) => b.got).slice(0, 8);

  const minis = (
    <div className="statv3-minis statv3-minis--inline">
      <MiniCard label="ВРЕМЯ" value={`${Math.floor(d.totalTimeMin / 60)}:${String(d.totalTimeMin % 60).padStart(2, '0')}`} unit="ч" />
      <MiniCard label="ТРЕНИРОВОК" value={d.totalWorkouts} />
      <MiniCard label="СР. ТЕМП" value={d.avgPace} unit="/км" />
    </div>
  );

  return (
    <div className="statv3-desk">
      <div className="statv3-desk__top">
        <SportSwitch sport={sport} setSport={setSport} />
        <span className="statv3-desk__spacer" />
        <PeriodSeg period={period} setPeriod={setPeriod} />
      </div>

      <div className="statv3-desk__body">
        <div className="statv3-desk__main">
          <HeroVolume d={d} period={period} rightSlot={minis} />
          <LoadCard load={load} headLabel="Тренды формы" />
          <RecentList recent={d.recent} onWorkoutClick={onWorkoutClick} />
        </div>

        <aside className="statv3-desk__side">
          <div className="card statv3-card">
            <div className="statv3-cardhead">Личные рекорды</div>
            <PrGrid records={records} compact />
          </div>

          <div className="card statv3-card">
            <div className="statv3-cardhead">Прогресс метрик</div>
            {metrics.length > 0
              ? <div className="statv3-trends">{metrics.map((m) => <TrendCard key={m.key} m={m} />)}</div>
              : <div className="statv3-records-empty">Недостаточно данных.</div>}
          </div>

          <div className="card statv3-card">
            <div className="statv3-cardhead">Достижения</div>
            {gotBadges.length > 0
              ? (
                <div className="statv3-ach-grid statv3-ach-grid--side">
                  {gotBadges.map((b, i) => <StaHexBadge key={i} b={b} size={52} />)}
                </div>
              )
              : <div className="statv3-records-empty">Пока нет наград.</div>}
          </div>
        </aside>
      </div>
    </div>
  );
}
