import { useEffect, useState } from 'react';
import { PrGrid, PredCard } from './blocks';

export default function RecordsTabV3({ api, viewContext = null }) {
  const [records, setRecords] = useState(null);
  const [pred, setPred] = useState(null);

  useEffect(() => {
    if (!api) return undefined;
    let cancelled = false;
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
    } else {
      setRecords({});
    }
    if (api.getRacePrediction) {
      api.getRacePrediction(viewContext)
        .then((res) => { if (!cancelled) setPred(res?.data || res); })
        .catch(() => { if (!cancelled) setPred(null); });
    }
    return () => { cancelled = true; };
  }, [api, viewContext]);

  return (
    <div className="statv3-tabbody">
      <div className="statv3-records-sub">Лучшие результаты по дистанциям</div>
      <PrGrid records={records} />
      <PredCard pred={pred} />
    </div>
  );
}
