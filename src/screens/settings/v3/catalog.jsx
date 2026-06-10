import { ProfileIcon, TrainingIcon, NotifIcon, CoachesIcon, IntegIcon, LookIcon, SecurityIcon } from './icons';
import ProfileSectionV3 from './sections/ProfileSectionV3';
import TrainingSectionV3 from './sections/TrainingSectionV3';
import NotifSectionV3 from './sections/NotifSectionV3';
import CoachesSectionV3 from './sections/CoachesSectionV3';
import IntegrationsSectionV3 from './sections/IntegrationsSectionV3';
import LookSectionV3 from './sections/LookSectionV3';
import SecuritySectionV3 from './sections/SecuritySectionV3';

// id — внутренний; tab — значение для deep-link ?tab= (совместимость со старыми ссылками).
export const CATS = [
  { id: 'profile', tab: 'profile', title: 'Профиль', sub: 'Имя, аватар, физданные', Icon: ProfileIcon, Component: ProfileSectionV3 },
  { id: 'training', tab: 'training', title: 'Тренировки', sub: 'Цель, режим, дни, темпы', Icon: TrainingIcon, Component: TrainingSectionV3 },
  { id: 'notif', tab: 'notifications', title: 'Уведомления', sub: 'Каналы и расписание', Icon: NotifIcon, Component: NotifSectionV3 },
  { id: 'coaches', tab: 'social', title: 'Тренеры', sub: 'Мои тренеры, заявки', Icon: CoachesIcon, Component: CoachesSectionV3 },
  { id: 'integ', tab: 'integrations', title: 'Интеграции', sub: 'Strava, Polar, Garmin…', Icon: IntegIcon, Component: IntegrationsSectionV3 },
  { id: 'look', tab: 'look', title: 'Внешний вид', sub: 'Тема оформления', Icon: LookIcon, Component: LookSectionV3 },
  { id: 'security', tab: 'security', title: 'Безопасность', sub: 'Email, пароль, PIN', Icon: SecurityIcon, Component: SecuritySectionV3 },
];

export const catById = (id) => CATS.find((c) => c.id === id) || null;
export const catByTab = (tab) => CATS.find((c) => c.tab === tab) || null;
