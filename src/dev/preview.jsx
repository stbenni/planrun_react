/**
 * Dev-превью экранов v3B без авторизации: /preview.html?screen=<id>&theme=<light|dark>.
 * Только для vite dev (в prod-сборку не входит — rollup-инпут лишь index.html).
 * Используется для скриншот-сверки экранов с моками design_handoff_v3b.
 */

import { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { MemoryRouter } from 'react-router-dom';
import OnboardingFlow, { Shell } from '../screens/OnboardingFlow';
import StepMode from '../components/Onboarding/StepMode';
import StepGoal from '../components/Onboarding/StepGoal';
import StepProfile from '../components/Onboarding/StepProfile';
import StepAssessment from '../components/Onboarding/StepAssessment';
import StepGenerating from '../components/Onboarding/StepGenerating';
import StepReady from '../components/Onboarding/StepReady';
import { createInitialOnboardingState } from '../components/Onboarding/onboardingForm';
import MobileDash from '../components/Dashboard/v3b/MobileDash';
import DesktopDash from '../components/Dashboard/v3b/DesktopDash';
import { PrDefs } from '../components/ui';
import '../index.css';

const params = new URLSearchParams(window.location.search);
const theme = params.get('theme') || 'light';
const screen = params.get('screen') || 'flow';
document.documentElement.setAttribute('data-theme', theme);
document.body.setAttribute('data-theme', theme);

const racePreset = {
  ...createInitialOnboardingState(),
  training_mode: 'ai',
  goal_type: 'race',
  race_distance: 'marathon',
  race_date: '2026-10-04',
  race_target_time: '3:29:59',
  first_name: 'Иван',
  gender: 'male',
  experience_level: 'intermediate',
  weekly_base_range: '25_40',
  weekly_base_km: '32',
  has_race_history: 'no',
  easy_pace_min: '6:00',
  preferred_days: ['tue', 'thu', 'sat', 'sun'],
};

const fakeAssessment = {
  verdict: 'challenging',
  vdot: 46.2,
  vdot_source: 'комфортный темп',
  messages: [{
    text: 'Цель 3:29:59 на марафоне при текущем объёме 32 км/нед — амбициозна, но достижима за 16 недель при росте объёма до 55–60 км.',
    suggestions: [{ text: 'Сдвинуть цель на 3:39:59 — комфортный запас', action: { field: 'race_target_time', value: '3:39:59' } }],
  }],
  predictions: { '5k': '23:40', '10k': '49:10', half: '1:49:30', marathon: '3:48:20' },
  training_paces: { easy: '6:25–6:55', marathon: '5:25', threshold: '5:02', interval: '4:38' },
};

const fakeSummary = {
  weeksTotal: 16,
  workouts: 86,
  peakKm: 62,
  daysPerWeek: 5,
  firstWeekRange: '15–21 июн',
  firstWeek: [
    { key: 'mon', rest: true, km: null },
    { key: 'tue', rest: false, km: 8 },
    { key: 'wed', rest: true, km: null },
    { key: 'thu', rest: false, km: 10 },
    { key: 'fri', rest: true, km: null },
    { key: 'sat', rest: false, km: 16 },
    { key: 'sun', rest: false, km: 6 },
  ],
};

const stubClient = {
  checkPlanStatus: async () => ({}),
  getPlan: async () => null,
  assessGoal: async () => null,
};

// ---------- фикстуры дашборда (зеркалят R3-данные мока) ----------

const noop = () => {};

const stubApi = {
  getPlanNotifications: async () => ({ items: [{ id: 1 }] }),
  getNotificationsDismissed: async () => ({}),
};

function weeksAgoIso(weeks, dayShift = 0) {
  const d = new Date(Date.now() - weeks * 7 * 86400000 + dayShift * 86400000);
  return d.toISOString().slice(0, 10);
}

// Форма как в проде (get_all_workouts_summary): объект-сводка на дату.
const dashWorkoutsByDate = (() => {
  const out = {};
  const kms = [34, 42, 38, 51, 46, 55, 49, 58];
  kms.forEach((km, i) => {
    const w = kms.length - 1 - i;
    out[weeksAgoIso(w, 0)] = { count: 1, distance: km * 0.5, duration: km * 0.5 * 5.4 };
    out[weeksAgoIso(w, 2)] = { count: 1, distance: km * 0.5, duration: km * 0.5 * 5.0 };
  });
  return out;
})();

const dashPlan = {
  weeks_data: Array.from({ length: 16 }, (_, i) => ({
    number: i + 19,
    start_date: (() => {
      const monday = new Date();
      const day = monday.getDay() || 7;
      monday.setDate(monday.getDate() - day + 1 - (5 - i) * 7);
      return monday.toISOString().slice(0, 10);
    })(),
    total_volume: `${40 + i} км`,
    days: {},
  })),
};

const dashProps = {
  api: stubApi,
  user: { race_date: '2026-10-04', race_distance: 'marathon', race_target_time: '3:29:59' },
  firstName: 'Иван',
  mode: 'ai',
  streak: 14,
  weekModel: {
    weekNumber: 24,
    planKm: 55,
    doneKm: 17,
    days: [
      { date: '2026-06-08', dow: 'Пн', km: 0, state: 'rest' },
      { date: '2026-06-09', dow: 'Вт', km: 9, state: 'done' },
      { date: '2026-06-10', dow: 'Ср', km: 8, state: 'done' },
      { date: '2026-06-11', dow: 'Чт', km: 10, state: 'today' },
      { date: '2026-06-12', dow: 'Пт', km: 0, state: 'rest' },
      { date: '2026-06-13', dow: 'Сб', km: 22, state: 'plan' },
      { date: '2026-06-14', dow: 'Вс', km: 6, state: 'plan' },
    ],
  },
  trainingLoad: {
    available: true,
    current: { tsb: 9, ctl: 62, atl: 53, acwr: 1.1, acwr_status: 'optimal' },
    daily: Array.from({ length: 28 }, (_, i) => ({
      tsb: -6 + i * 0.55,
      trimp: [40, 0, 86, 52, 0, 110, 64][i % 7],
    })),
  },
  briefing: 'Восстановление полное. Сегодня можно работать на пороге — окно продуктивности до 11:00.',
  todayWorkout: {
    type: 'tempo',
    is_key_workout: true,
    date: '2026-06-11',
    text: '10 км · 0:48:00\nТемп: 4:35 мин/км\nРазминка 2 км, темповый блок 6 км в темпе 4:30–4:40, заминка 2 км\nтемповая ключевая',
  },
  hasAnyPlannedWorkout: true,
  plan: dashPlan,
  workoutsByDate: dashWorkoutsByDate,
  onModeClick: noop,
  onStart: noop,
  onOpenCalendar: noop,
  onOpenStats: noop,
};

const dashPrediction = {
  vdot: 52.1,
  predictions: { marathon: { formatted: '3:34:10' } },
  training_paces: { easy: '5:45–6:15', marathon: '5:00', threshold: '4:35', interval: '4:12', repetition: '3:55' },
};

const dashRecords = [
  { dist: '5 км', time: '21:48', fresh: false },
  { dist: '10 км', time: '45:32', fresh: false },
  { dist: '21,1', time: '1:43:05', fresh: true },
];

function StatefulStep({ Component, preset, extraProps }) {
  const [formData, setFormData] = useState(preset);
  const onChange = (f, v) => setFormData((p) => ({ ...p, [f]: v }));
  const onToggleArray = (field, value, checked) => {
    setFormData((prev) => {
      const arr = prev[field] || [];
      return { ...prev, [field]: checked ? [...arr, value] : arr.filter((x) => x !== value) };
    });
  };
  return <Component formData={formData} onChange={onChange} onToggleArray={onToggleArray} {...extraProps} />;
}

const SCREENS = {
  flow: () => <OnboardingFlow />,
  'dash-mobile': () => (
    <div style={{ minHeight: '100vh', background: 'var(--pr-bg)' }}>
      <div style={{ maxWidth: 560, margin: '0 auto' }}>
        <MobileDash {...dashProps} vdot={dashPrediction.vdot} />
      </div>
    </div>
  ),
  'dash-desktop': () => (
    <div style={{ minHeight: '100vh', background: 'var(--pr-bg)' }}>
      <DesktopDash
        {...dashProps}
        prediction={dashPrediction}
        records={dashRecords}
        syncedProvider="Garmin"
        onNavigate={noop}
      />
    </div>
  ),
  'ob-mode': () => (
    <Shell filled={1} total={4}><StatefulStep Component={StepMode} preset={{ ...racePreset, training_mode: 'ai' }} /></Shell>
  ),
  'ob-goal': () => (
    <Shell filled={2} total={4}><StatefulStep Component={StepGoal} preset={racePreset} /></Shell>
  ),
  'ob-profile': () => (
    <Shell filled={3} total={4}><StatefulStep Component={StepProfile} preset={racePreset} /></Shell>
  ),
  'ob-assess': () => (
    <Shell filled={4} total={4}>
      <StatefulStep Component={StepAssessment} preset={racePreset} extraProps={{ assessment: fakeAssessment, loading: false, onApplySuggestion: noop }} />
    </Shell>
  ),
  'ob-generating': () => (
    <Shell filled={4} total={4}>
      <StepGenerating client={stubClient} subtitle="Марафон · 04.10 · цель 3:29:59." onReady={noop} onDashboard={noop} />
    </Shell>
  ),
  'ob-ready': () => (
    <Shell filled={4} total={4}>
      <StepReady planMode summary={fakeSummary} formData={racePreset} planMessage={null} onOpenCalendar={noop} />
    </Shell>
  ),
};

const Screen = SCREENS[screen] || SCREENS.flow;

createRoot(document.getElementById('root')).render(
  <MemoryRouter>
    <PrDefs />
    <Screen />
  </MemoryRouter>
);
