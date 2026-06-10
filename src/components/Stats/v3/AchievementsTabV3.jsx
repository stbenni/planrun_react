import { useEffect, useMemo, useState } from 'react';
import { computeAchievements } from '../statsAchievements';
import { PointsHero, AchCategory } from './blocks';

export default function AchievementsTabV3({ api, viewContext = null, rawData }) {
  const [vdot, setVdot] = useState(null);
  const [records, setRecords] = useState(null);

  useEffect(() => {
    if (!api) return undefined;
    let cancelled = false;
    if (api.getRacePrediction) {
      api.getRacePrediction(viewContext)
        .then((res) => { const d = res?.data || res; if (!cancelled) setVdot(d?.vdot ?? null); })
        .catch(() => {});
    }
    if (api.getPersonalRecords) {
      api.getPersonalRecords()
        .then((res) => {
          if (cancelled) return;
          const arr = res?.data?.records ?? res?.records ?? [];
          const byKey = {};
          arr.forEach((r) => { if (r?.distance_label) byKey[r.distance_label] = r; });
          setRecords(byKey);
        })
        .catch(() => {});
    }
    return () => { cancelled = true; };
  }, [api, viewContext]);

  const ach = useMemo(
    () => computeAchievements({ workoutsList: rawData?.workoutsList || [], vdot, records }),
    [rawData, vdot, records],
  );

  return (
    <div className="statv3-tabbody">
      <PointsHero ach={ach} />
      {ach.categories.map((c) => <AchCategory key={c.cat} c={c} />)}
    </div>
  );
}
