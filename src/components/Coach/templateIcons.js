/**
 * Иконки для шаблонов тренировок.
 * Поле template.emoji в БД хранит ключ (zap/interval/easy/...). Для обратной
 * совместимости со старыми записями (где лежит сам эмодзи "⚡") — мапа EMOJI_TO_KEY.
 */

import {
  ZapIcon, RepeatIcon, FeatherIcon, RouteIcon, ShuffleIcon,
  PersonStandingIcon, DumbbellIcon, MoonIcon, RunningIcon,
  MountainIcon, TimerIcon, FlagIcon,
} from '../common/Icons';

export const TEMPLATE_ICON_OPTIONS = [
  { key: 'zap', Icon: ZapIcon, label: 'Темповая' },
  { key: 'interval', Icon: RepeatIcon, label: 'Интервалы' },
  { key: 'easy', Icon: FeatherIcon, label: 'Лёгкая' },
  { key: 'long', Icon: RouteIcon, label: 'Длительная' },
  { key: 'fartlek', Icon: ShuffleIcon, label: 'Фартлек' },
  { key: 'sbu', Icon: PersonStandingIcon, label: 'СБУ' },
  { key: 'ofp', Icon: DumbbellIcon, label: 'ОФП' },
  { key: 'rest', Icon: MoonIcon, label: 'Отдых' },
  { key: 'run', Icon: RunningIcon, label: 'Бег' },
  { key: 'hill', Icon: MountainIcon, label: 'Холмы' },
  { key: 'control', Icon: TimerIcon, label: 'Контроль' },
  { key: 'race', Icon: FlagIcon, label: 'Гонка' },
];

const EMOJI_TO_KEY = {
  '⚡': 'zap',
  '🔥': 'interval',
  '🟢': 'easy',
  '🛣': 'long',
  '🎲': 'fartlek',
  '🦵': 'sbu',
  '💪': 'ofp',
  '💤': 'rest',
  '🏃': 'run',
  '🎯': 'control',
  '🏔': 'hill',
  '⛰': 'hill',
  '🥇': 'race',
};

const KEY_TO_ICON = TEMPLATE_ICON_OPTIONS.reduce((acc, o) => { acc[o.key] = o.Icon; return acc; }, {});

/** Возвращает Icon-компонент по значению из поля template.emoji (либо ключ 'zap', либо старый эмодзи). */
export function getTemplateIcon(value) {
  if (!value) return null;
  if (KEY_TO_ICON[value]) return KEY_TO_ICON[value];
  const key = EMOJI_TO_KEY[value];
  return key ? KEY_TO_ICON[key] : null;
}
