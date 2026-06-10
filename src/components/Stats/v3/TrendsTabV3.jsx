import { useEffect, useMemo, useState } from 'react';
import { processTrendsV3, processLoadV3 } from '../statsV3Utils';
import { TrendCard, LoadCard } from './blocks';

export default function TrendsTabV3({ api, viewContext = null, rawData }) {
  const [load, setLoad] = useState({ available: false });

  useEffect(() => {
    if (!api?.getTrainingLoad) return undefined;
    let cancelled = false;
    api.getTrainingLoad(viewContext, 90)
      .then((res) => { if (!cancelled) setLoad(processLoadV3(res?.data || res)); })
      .catch(() => { if (!cancelled) setLoad({ available: false }); });
    return () => { cancelled = true; };
  }, [api, viewContext]);

  const metrics = useMemo(
    () => processTrendsV3(rawData?.workoutsList || [], 'run', 12),
    [rawData],
  );

  return (
    <div className="statv3-tabbody">
      {metrics.length > 0 ? (
        <div className="statv3-trends">
          {metrics.map((m) => <TrendCard key={m.key} m={m} />)}
        </div>
      ) : (
        <div className="card statv3-card statv3-records-empty">
          Недостаточно тренировок для трендов — нужно хотя бы несколько недель данных.
        </div>
      )}
      <LoadCard load={load} />
    </div>
  );
}
