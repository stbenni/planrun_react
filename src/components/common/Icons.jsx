/**
 * Единый пул иконок проекта PlanRun
 * Источники: lucide-react (основной), RunningIcon — кастомный SVG
 * Стиль: line/stroke, 24×24 viewBox, strokeWidth 1.8
 */

import React from 'react';
import {
  Footprints,
  Mountain,
  Bike,
  Waves,
  Dumbbell,
  Route,
  Clock,
  Gauge,
  Zap,
  Moon,
  Check,
  Calendar,
  Bot,
  Mail,
  Heart,
  AlertTriangle,
  TrendingUp,
  Target,
  GraduationCap,
  User,
  Lock,
  Link2,
  LogOut,
  Upload,
  Trophy,
  Flame,
  BarChart3,
  Fingerprint,
  MessageCircle,
  Bell,
  Smartphone,
  Image,
  Palette,
  Users,
  ClipboardList,
  MapPin,
  Trash2,
  Leaf,
  PenLine,
  Pointer,
  Medal,
  Flag,
  XCircle,
  Settings,
  SkipForward,
} from 'lucide-react';

const ICON_SIZE = 20;
const STROKE_WIDTH = 1.8;
const iconProps = {
  size: ICON_SIZE,
  strokeWidth: STROKE_WIDTH,
  'aria-hidden': true,
};

// --- Activity (типы тренировок) ---

/** Бег — бегущий человек (fill-стиль) */
export function RunningIcon({ className = '', size = ICON_SIZE, ...props }) {
  return (
    <svg
      className={className}
      width={size}
      height={size}
      viewBox="0 0 32 32"
      fill="currentColor"
      aria-hidden
      {...props}
    >
      <path d="m5.939 29c-.124 0-.249-.046-.346-.139-.199-.191-.206-.507-.016-.707l6.218-6.499c.192-.199.509-.207.707-.016.199.191.206.507.016.707l-6.218 6.499c-.098.103-.229.155-.361.155z" />
      <path d="m25 12c-1.654 0-3-1.346-3-3s1.346-3 3-3 3 1.346 3 3-1.346 3-3 3zm0-5c-1.103 0-2 .897-2 2s.897 2 2 2 2-.897 2-2-.897-2-2-2z" />
      <path d="m22.5 31h-18.5c-.276 0-.5-.224-.5-.5s.224-.5.5-.5h18.5c.276 0 .5.224.5.5s-.224.5-.5.5z" />
      <path d="m22.5 31h-18.067c-.788 0-1.483-.444-1.814-1.158s-.222-1.532.287-2.133l7.213-8.532c.179-.211.494-.236.705-.059.21.178.237.494.059.705l-7.213 8.532c-.258.305-.312.704-.144 1.066.167.363.507.579.907.579h18.067c.276 0 .5.224.5.5s-.224.5-.5.5z" />
      <path d="m15.957 27.352c-.156 0-.311-.073-.408-.211-.159-.226-.106-.538.119-.697l4.558-3.226c.228-.183.368-.493.353-.813s-.186-.616-.454-.79l-3.205-2.362c-.115-.085-.188-.215-.201-.357s.036-.283.134-.387l3.972-4.225c.189-.202.506-.211.707-.022s.211.505.021.707l-3.586 3.814 2.728 2.011c.513.332.852.923.883 1.563s-.249 1.261-.749 1.661l-4.581 3.244c-.09.06-.19.09-.291.09z" />
      <path d="m14.583 29c-.143 0-.284-.061-.383-.179l-.399-.477c-.835-.994-.776-2.44.137-3.364l2.289-2.315-3.224-2.064c-.698-.447-1.184-1.17-1.333-1.985s.048-1.664.542-2.329l4.292-5.785-2.151-2.102-3.76 1.62c-.372.16-.78.165-1.151.017s-.662-.433-.82-.801c-.326-.757.024-1.642.783-1.97l4.684-2.018c.563-.243 1.206-.123 1.642.304l2.598 1.83c.663.47 1.271 1.016 1.807 1.623l5.147 5.832 3.579-1.96c.729-.396 1.641-.129 2.036.596.396.726.129 1.639-.596 2.036l-4.579 2.507c-.129.07-.266.121-.407.151-.496.105-1.001-.043-1.361-.394l-1.802-1.441c-.216-.173-.25-.487-.078-.703.175-.216.489-.249.703-.078l1.839 1.474c.157.15.327.199.49.164.049-.01.093-.027.137-.05l4.578-2.506c.242-.132.331-.437.199-.679-.133-.242-.438-.333-.68-.198l-3.929 2.151c-.206.113-.46.068-.615-.107l-5.412-6.132c-.484-.549-1.034-1.043-1.635-1.468l-2.658-1.88c-.205-.192-.42-.232-.606-.152l-4.685 2.016c-.253.109-.37.403-.261.656.053.124.149.219.272.268s.262.047.385-.006l4.066-1.752c.188-.082.402-.04.548.101l2.704 2.64c.179.175.201.455.052.656l-4.553 6.136c-.334.45-.462 1.001-.361 1.553.102.551.417 1.021.889 1.324l3.744 2.397c.127.081.211.216.228.366s-.035.299-.142.407l-2.724 2.755c-.548.554-.583 1.422-.082 2.019l.399.477c.178.212.15.527-.062.705-.092.076-.207.114-.32.114z" />
      <path d="m3.5 7h-2c-.276 0-.5-.224-.5-.5s.224-.5.5-.5h2c.276 0 .5.224.5.5s-.224.5-.5.5z" />
      <path d="m9.5 17h-8c-.276 0-.5-.224-.5-.5s.224-.5.5-.5h8c.276 0 .5.224.5.5s-.224.5-.5.5z" />
      <path d="m11.5 5h-7c-.276 0-.5-.224-.5-.5s.224-.5.5-.5h7c.276 0 .5.224.5.5s-.224.5-.5.5z" />
      <circle cx="24.5" cy="30.5" r=".5" />
    </svg>
  );
}

export function WalkingIcon(props) {
  return <Footprints {...iconProps} {...props} />;
}
export function HikingIcon(props) {
  return <Mountain {...iconProps} {...props} />;
}
export function CyclingIcon(props) {
  return <Bike {...iconProps} {...props} />;
}
export function SwimmingIcon(props) {
  return <Waves {...iconProps} {...props} />;
}
export function OtherIcon(props) {
  return <Dumbbell {...iconProps} {...props} />;
}
export function SbuIcon(props) {
  return <Zap {...iconProps} {...props} />;
}
export function RestIcon(props) {
  return <Moon {...iconProps} {...props} />;
}
export function CompletedIcon(props) {
  return <Check {...iconProps} {...props} />;
}

// --- Metrics ---

export function DistanceIcon(props) {
  return <Route {...iconProps} {...props} />;
}
export function TimeIcon(props) {
  return <Clock {...iconProps} {...props} />;
}
export function PaceIcon(props) {
  return <Gauge {...iconProps} {...props} />;
}

// --- UI / Chat / Notifications ---

export function BotIcon(props) {
  return <Bot {...iconProps} {...props} />;
}
export function MailIcon(props) {
  return <Mail {...iconProps} {...props} />;
}
export function CalendarIcon(props) {
  return <Calendar {...iconProps} {...props} />;
}
export function CheckIcon(props) {
  return <Check {...iconProps} {...props} />;
}
export function AlertTriangleIcon(props) {
  return <AlertTriangle {...iconProps} {...props} />;
}
export function HeartIcon(props) {
  return <Heart {...iconProps} {...props} />;
}
export function ZapIcon(props) {
  return <Zap {...iconProps} {...props} />;
}
export function TrendingUpIcon(props) {
  return <TrendingUp {...iconProps} {...props} />;
}
export function TargetIcon(props) {
  return <Target {...iconProps} {...props} />;
}
export function GraduationCapIcon(props) {
  return <GraduationCap {...iconProps} {...props} />;
}

// --- Дополнительные UI ---

export function UserIcon(props) {
  return <User {...iconProps} {...props} />;
}
export function LockIcon(props) {
  return <Lock {...iconProps} {...props} />;
}
export function LinkIcon(props) {
  return <Link2 {...iconProps} {...props} />;
}
export function LogOutIcon(props) {
  return <LogOut {...iconProps} {...props} />;
}
export function UploadIcon(props) {
  return <Upload {...iconProps} {...props} />;
}
export function TrophyIcon(props) {
  return <Trophy {...iconProps} {...props} />;
}
export function FlameIcon(props) {
  return <Flame {...iconProps} {...props} />;
}
export function BarChartIcon(props) {
  return <BarChart3 {...iconProps} {...props} />;
}
export function FingerprintIcon(props) {
  return <Fingerprint {...iconProps} {...props} />;
}
export function MessageCircleIcon(props) {
  return <MessageCircle {...iconProps} {...props} />;
}
export function BellIcon(props) {
  return <Bell {...iconProps} {...props} />;
}
export function SmartphoneIcon(props) {
  return <Smartphone {...iconProps} {...props} />;
}
export function ImageIcon(props) {
  return <Image {...iconProps} {...props} />;
}
export function PaletteIcon(props) {
  return <Palette {...iconProps} {...props} />;
}
export function UsersIcon(props) {
  return <Users {...iconProps} {...props} />;
}
export function ClipboardListIcon(props) {
  return <ClipboardList {...iconProps} {...props} />;
}
export function MapPinIcon(props) {
  return <MapPin {...iconProps} {...props} />;
}
export function MountainIcon(props) {
  return <Mountain {...iconProps} {...props} />;
}
export function TrashIcon(props) {
  return <Trash2 {...iconProps} {...props} />;
}
export function LeafIcon(props) {
  return <Leaf {...iconProps} {...props} />;
}
export function PenLineIcon(props) {
  return <PenLine {...iconProps} {...props} />;
}
export function PointerIcon(props) {
  return <Pointer {...iconProps} {...props} />;
}
export function MedalIcon(props) {
  return <Medal {...iconProps} {...props} />;
}
export function FlagIcon(props) {
  return <Flag {...iconProps} {...props} />;
}
export function XCircleIcon(props) {
  return <XCircle {...iconProps} {...props} />;
}
export function SettingsIcon(props) {
  return <Settings {...iconProps} {...props} />;
}
export function SkipForwardIcon(props) {
  return <SkipForward {...iconProps} {...props} />;
}

// --- Activity type mapping (для RecentWorkoutsList и др.) ---

const ACTIVITY_ICONS = {
  running: RunningIcon,
  walking: WalkingIcon,
  hiking: HikingIcon,
  cycling: CyclingIcon,
  swimming: SwimmingIcon,
  other: OtherIcon,
  easy: RunningIcon,
  long: RunningIcon,
  tempo: RunningIcon,
  interval: RunningIcon,
  sbu: SbuIcon,
  fartlek: RunningIcon,
  rest: RestIcon,
};

export function ActivityTypeIcon({ type, className = '', ...props }) {
  const Icon = ACTIVITY_ICONS[type] || RunningIcon;
  return <Icon className={className} {...props} />;
}
