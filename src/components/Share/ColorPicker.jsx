import { useState, useRef, useCallback } from 'react';

const clamp = (n, min, max) => Math.min(max, Math.max(min, n));

function hexToRgb(hex) {
  const m = /^#?([0-9a-f]{6})$/i.exec(String(hex || '').trim());
  if (!m) return { r: 255, g: 255, b: 255 };
  const n = parseInt(m[1], 16);
  return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
}

function rgbToHex(r, g, b) {
  const h = (x) => clamp(Math.round(x), 0, 255).toString(16).padStart(2, '0');
  return `#${h(r)}${h(g)}${h(b)}`.toUpperCase();
}

function rgbToHsv(r, g, b) {
  r /= 255; g /= 255; b /= 255;
  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  const d = max - min;
  let h = 0;
  if (d) {
    if (max === r) h = ((g - b) / d) % 6;
    else if (max === g) h = (b - r) / d + 2;
    else h = (r - g) / d + 4;
    h *= 60;
    if (h < 0) h += 360;
  }
  return { h, s: max === 0 ? 0 : d / max, v: max };
}

function hsvToRgb(h, s, v) {
  const c = v * s;
  const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
  const m = v - c;
  let r = 0; let g = 0; let b = 0;
  if (h < 60) { r = c; g = x; } else if (h < 120) { r = x; g = c; } else if (h < 180) { g = c; b = x; } else if (h < 240) { g = x; b = c; } else if (h < 300) { r = x; b = c; } else { r = c; b = x; }
  return { r: (r + m) * 255, g: (g + m) * 255, b: (b + m) * 255 };
}

function hsvToHex(h, s, v) {
  const { r, g, b } = hsvToRgb(h, s, v);
  return rgbToHex(r, g, b);
}

export default function ColorPicker({ value, onChange, onClose }) {
  const [hsv, setHsv] = useState(() => {
    const { r, g, b } = hexToRgb(value);
    return rgbToHsv(r, g, b);
  });
  const [hexText, setHexText] = useState(() => hsvToHex(hsv.h, hsv.s, hsv.v));
  const hsvRef = useRef(hsv);
  const svRef = useRef(null);
  const hueRef = useRef(null);

  const emit = useCallback((next) => {
    hsvRef.current = next;
    setHsv(next);
    const hex = hsvToHex(next.h, next.s, next.v);
    setHexText(hex);
    onChange?.(hex);
  }, [onChange]);

  const moveSV = useCallback((cx, cy) => {
    const el = svRef.current;
    if (!el) return;
    const r = el.getBoundingClientRect();
    const s = clamp((cx - r.left) / r.width, 0, 1);
    const v = 1 - clamp((cy - r.top) / r.height, 0, 1);
    emit({ ...hsvRef.current, s, v });
  }, [emit]);

  const moveHue = useCallback((cx) => {
    const el = hueRef.current;
    if (!el) return;
    const r = el.getBoundingClientRect();
    emit({ ...hsvRef.current, h: clamp((cx - r.left) / r.width, 0, 1) * 360 });
  }, [emit]);

  const startDrag = useCallback((moveFn) => (e) => {
    e.preventDefault();
    moveFn(e.clientX, e.clientY);
    const onMove = (ev) => moveFn(ev.clientX, ev.clientY);
    const onUp = () => {
      window.removeEventListener('pointermove', onMove);
      window.removeEventListener('pointerup', onUp);
    };
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
  }, []);

  const onHexInput = (e) => {
    const t = e.target.value;
    setHexText(t);
    if (/^#?[0-9a-f]{6}$/i.test(t.trim())) {
      const { r, g, b } = hexToRgb(t);
      emit(rgbToHsv(r, g, b));
    }
  };

  const hueColor = hsvToHex(hsv.h, 1, 1);
  const current = hsvToHex(hsv.h, hsv.s, hsv.v);

  return (
    <div className="sharecomp-cp">
      <div
        ref={svRef}
        className="sharecomp-cp-sv"
        style={{ background: `linear-gradient(to top, #000, transparent), linear-gradient(to right, #fff, transparent), ${hueColor}` }}
        onPointerDown={startDrag(moveSV)}
      >
        <span
          className="sharecomp-cp-sv-thumb"
          style={{ left: `${hsv.s * 100}%`, top: `${(1 - hsv.v) * 100}%`, background: current }}
        />
      </div>
      <div ref={hueRef} className="sharecomp-cp-hue" onPointerDown={startDrag(moveHue)}>
        <span className="sharecomp-cp-hue-thumb" style={{ left: `${(hsv.h / 360) * 100}%` }} />
      </div>
      <div className="sharecomp-cp-foot">
        <span className="sharecomp-cp-preview" style={{ background: current }} />
        <input
          className="sharecomp-cp-hex"
          value={hexText}
          onChange={onHexInput}
          spellCheck={false}
          maxLength={7}
          aria-label="HEX"
        />
        <button type="button" className="sharecomp-cp-done" onClick={onClose}>Готово</button>
      </div>
    </div>
  );
}
