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

const noop = () => {};

const SCREENS = {
  flow: () => <OnboardingFlow />,
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
