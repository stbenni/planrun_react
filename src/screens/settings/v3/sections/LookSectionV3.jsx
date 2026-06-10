import { Group, Row } from '../primitives';

const THEMES = [
  { id: 'light', label: 'Светлая', swatch: 'linear-gradient(180deg,#FAF7F3,#F4F7FB)' },
  { id: 'dark', label: 'Тёмная', swatch: 'linear-gradient(180deg,#0F151D,#0B1015)' },
  { id: 'system', label: 'Системная', swatch: 'linear-gradient(120deg,#FAF7F3 50%,#0F151D 50%)' },
];

export default function LookSectionV3({ ctx }) {
  const { themePreference, onThemeChange } = ctx;
  return (
    <Group label="Тема оформления" footer="Тёмная экономит батарею на AMOLED и удобна вечером">
      {THEMES.map((t) => {
        const on = themePreference === t.id || (t.id === 'system' && !['light', 'dark'].includes(themePreference));
        return (
          <Row key={t.id} onClick={() => onThemeChange(t.id)}>
            <div className="sv3-theme-swatch" style={{ background: t.swatch }} />
            <span className="sv3-theme-label">{t.label}</span>
            <span className={`sv3-radio ${on ? 'sv3-radio--on' : ''}`}>{on ? '✓' : ''}</span>
          </Row>
        );
      })}
    </Group>
  );
}
