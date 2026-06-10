import { useMemo } from 'react';
import { processOverviewV3 } from '../statsV3Utils';
import {
  SportSwitch, PeriodSeg, HeroVolume, MiniRow, ActivityChart, RecentList,
} from './blocks';

export default function OverviewTabV3({
  rawData, sport, setSport, period, setPeriod, onWorkoutClick,
}) {
  const d = useMemo(
    () => processOverviewV3(rawData?.workoutsList || [], rawData?.plan, period, sport),
    [rawData, period, sport],
  );

  return (
    <div className="statv3-tabbody">
      <SportSwitch sport={sport} setSport={setSport} />
      <PeriodSeg period={period} setPeriod={setPeriod} />
      <HeroVolume d={d} period={period} />
      <MiniRow d={d} />
      <ActivityChart d={d} />
      <RecentList recent={d.recent} onWorkoutClick={onWorkoutClick} onShare={onWorkoutClick} />
    </div>
  );
}
