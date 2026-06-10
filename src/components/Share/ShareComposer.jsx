import {
  useCallback, useEffect, useMemo, useRef, useState,
} from 'react';
import { createPortal } from 'react-dom';
import {
  CloseIcon, ImageIcon, ShareIcon,
} from '../common/Icons';
import { buildShareModel } from '../../utils/shareWorkoutModel';
import {
  pickPhotoDataUrl, loadImage, canvasToBlob, shareImageBlob, saveImageBlob,
} from '../../utils/shareImage';
import { TEMPLATES, TEMPLATE_ORDER } from './shareTemplates';
import { tapHaptic } from '../../utils/haptics';
import ColorPicker from './ColorPicker';
import './ShareComposer.css';

const EXPORT_W = 1080;
const ASPECTS = { '9:16': { label: 'Stories', h: 1920 }, '4:5': { label: 'Пост', h: 1350 } };
const ACCENT = '#FF6B2C';
const TEXT_COLORS = ['#FFFFFF', '#FC4C02', '#FACC15', '#A3E635', '#38BDF8', '#E879F9'];
const GRADIENTS = {
  dark: { label: 'Тёмный', stops: ['#11161F', '#1A2230', '#0E1218'] },
  midnight: { label: 'Полночь', stops: ['#0F0C29', '#302B63', '#24243E'] },
  ocean: { label: 'Океан', stops: ['#0F2027', '#203A43', '#2C5364'] },
  royal: { label: 'Синий', stops: ['#141E30', '#243B55'] },
  teal: { label: 'Бирюза', stops: ['#093028', '#237A57'] },
  forest: { label: 'Лес', stops: ['#134E4A', '#065F46', '#022C22'] },
  ember: { label: 'Угли', stops: ['#1F1C18', '#8E2D03', '#FC4C02'] },
  magma: { label: 'Магма', stops: ['#420516', '#8E2D03', '#FF6A00'] },
  sunset: { label: 'Закат', stops: ['#3A1C71', '#D76D77', '#FFAF7B'] },
  rose: { label: 'Роза', stops: ['#642B73', '#C6426E'] },
  grape: { label: 'Виноград', stops: ['#41295A', '#2F0743'] },
  slate: { label: 'Графит', stops: ['#232526', '#414345'] },
};
const TABS = [['bg', 'Фон'], ['data', 'Данные'], ['style', 'Цвет']];

function coverParams(img, cw, ch, zoom) {
  const base = Math.max(cw / img.width, ch / img.height);
  const scale = base * zoom;
  return { drawW: img.width * scale, drawH: img.height * scale };
}

function drawGradient(ctx, w, h, stops) {
  const g = ctx.createLinearGradient(0, 0, w, h);
  stops.forEach((c, i) => g.addColorStop(i / (stops.length - 1), c));
  ctx.fillStyle = g; ctx.fillRect(0, 0, w, h);
}

function sampleBrightness(srcCanvas, w) {
  try {
    const t = document.createElement('canvas'); t.width = 16; t.height = 16;
    const tc = t.getContext('2d');
    const h = srcCanvas.height;
    tc.drawImage(srcCanvas, 0, h * 0.5, w, h * 0.5, 0, 0, 16, 16);
    const d = tc.getImageData(0, 0, 16, 16).data;
    let sum = 0;
    for (let i = 0; i < d.length; i += 4) sum += 0.2126 * d[i] + 0.7152 * d[i + 1] + 0.0722 * d[i + 2];
    return sum / (d.length / 4) / 255;
  } catch (e) { return 0; }
}

export default function ShareComposer({
  open, onClose, api, date, workout, timeline,
}) {
  const model = useMemo(() => buildShareModel({ date, workout, timeline }), [date, workout, timeline]);
  const hasRoute = (model?.routePoints?.length || 0) >= 2;

  const [aspect, setAspect] = useState('9:16');
  const [template, setTemplate] = useState('minimal');
  const [bgMode, setBgMode] = useState('gradient');
  const [gradKey, setGradKey] = useState('dark');
  const [textColor, setTextColor] = useState('auto');
  const [showRoute, setShowRoute] = useState(true);
  const [selected, setSelected] = useState(() => (model?.metrics || []).map((m) => m.key));
  const [zoom, setZoom] = useState(1);
  const [hasPhoto, setHasPhoto] = useState(false);
  const [status, setStatus] = useState('idle');
  const [tab, setTab] = useState('bg');
  const [bgMapLoading, setBgMapLoading] = useState(false);
  const [colorPickerOpen, setColorPickerOpen] = useState(false);
  const [pickerClosing, setPickerClosing] = useState(false);
  const pickerCloseTimer = useRef(null);
  const [cropOpen, setCropOpen] = useState(false);

  const canvasRef = useRef(null);
  const cropCanvasRef = useRef(null);
  const photoRef = useRef(null);
  const mapRef = useRef(null);
  const routeMapRef = useRef(null);
  const offsetRef = useRef({ x: 0, y: 0 });
  const gestureRef = useRef(null);
  const cropDragRef = useRef(null);
  const beforePickRef = useRef(null);
  const ghostRef = useRef(null);
  const rafRef = useRef(0);

  useEffect(() => {
    setSelected((model?.metrics || []).map((m) => m.key));
  }, [workout?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  const tmplKey = (template === 'trace' && !hasRoute) ? 'card' : template;

  const draw = useCallback(() => {
    const canvas = canvasRef.current;
    if (!canvas || !model) return;
    const w = EXPORT_W;
    const h = ASPECTS[aspect].h;
    if (canvas.width !== w) canvas.width = w;
    if (canvas.height !== h) canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, w, h);

    // фон
    const photo = photoRef.current;
    const mapImg = mapRef.current;
    if (bgMode === 'photo' && photo) {
      const { drawW, drawH } = coverParams(photo, w, h, zoom);
      let dx = (w - drawW) / 2 + offsetRef.current.x;
      let dy = (h - drawH) / 2 + offsetRef.current.y;
      dx = Math.min(0, Math.max(w - drawW, dx));
      dy = Math.min(0, Math.max(h - drawH, dy));
      offsetRef.current = { x: dx - (w - drawW) / 2, y: dy - (h - drawH) / 2 };
      ctx.drawImage(photo, dx, dy, drawW, drawH);
    } else if (bgMode === 'map' && mapImg) {
      const { drawW, drawH } = coverParams(mapImg, w, h, 1);
      ctx.drawImage(mapImg, (w - drawW) / 2, (h - drawH) / 2, drawW, drawH);
    } else {
      drawGradient(ctx, w, h, (GRADIENTS[gradKey] || GRADIENTS.dark).stops);
    }

    // авто-контраст: при 'auto' — белый текст + нижний скрим под яркость фона
    let effColor = textColor;
    let botScrim = 0.62;
    if (textColor === 'auto') {
      effColor = '#FFFFFF';
      botScrim = Math.min(0.86, 0.42 + sampleBrightness(canvas, w) * 0.5);
    }
    const sb = ctx.createLinearGradient(0, h * 0.42, 0, h);
    sb.addColorStop(0, 'rgba(8,11,16,0)'); sb.addColorStop(1, `rgba(8,11,16,${botScrim})`);
    ctx.fillStyle = sb; ctx.fillRect(0, 0, w, h);
    const st = ctx.createLinearGradient(0, 0, 0, h * 0.32);
    st.addColorStop(0, 'rgba(8,11,16,0.42)'); st.addColorStop(1, 'rgba(8,11,16,0)');
    ctx.fillStyle = st; ctx.fillRect(0, 0, w, h);

    const selMetrics = model.metrics.filter((m) => selected.includes(m.key));
    const tpl = TEMPLATES[tmplKey] || TEMPLATES.card;
    tpl.draw(ctx, w, h, {
      model, metrics: selMetrics, textColor: effColor, accent: ACCENT, routeMapImg: routeMapRef.current, showRoute,
    });
  }, [aspect, bgMode, gradKey, zoom, textColor, selected, tmplKey, model, showRoute]);

  const scheduleDraw = useCallback(() => {
    cancelAnimationFrame(rafRef.current);
    rafRef.current = requestAnimationFrame(draw);
  }, [draw]);

  useEffect(() => {
    if (!open) return undefined;
    let alive = true;
    const ready = () => { if (alive) scheduleDraw(); };
    if (document.fonts?.load) {
      Promise.all([
        document.fonts.load('800 100px Montserrat'),
        document.fonts.load('600 30px Montserrat'),
        document.fonts.load('italic 800 40px Jost'),
      ]).then(ready).catch(ready);
    } else if (document.fonts?.ready) {
      document.fonts.ready.then(ready).catch(ready);
    }
    scheduleDraw();
    return () => { alive = false; cancelAnimationFrame(rafRef.current); };
  }, [open, scheduleDraw]);

  useEffect(() => { if (open) scheduleDraw(); }, [open, scheduleDraw]);

  // фон-карта по запросу
  useEffect(() => {
    if (!open || bgMode !== 'map' || mapRef.current || !api || !hasRoute || !workout?.id) return undefined;
    let alive = true;
    setBgMapLoading(true);
    (async () => {
      try {
        const res = await api.getWorkoutShareMap(workout.id, { width: 540, height: 960, scale: 2 });
        if (!alive || !res?.blob) return;
        const img = await loadImage(URL.createObjectURL(res.blob));
        if (!alive) return;
        mapRef.current = img; scheduleDraw();
      } catch (e) { if (alive) setBgMode('gradient'); } finally { if (alive) setBgMapLoading(false); }
    })();
    return () => { alive = false; };
  }, [open, bgMode, api, hasRoute, workout?.id, scheduleDraw]);

  // мини-карта для шаблона «Карточка»
  useEffect(() => {
    if (!open || !hasRoute || routeMapRef.current || !api || !workout?.id) return undefined;
    let alive = true;
    (async () => {
      try {
        const res = await api.getWorkoutShareMap(workout.id, { width: 436, height: 165, scale: 2 });
        if (!alive || !res?.blob) return;
        const img = await loadImage(URL.createObjectURL(res.blob));
        if (!alive) return;
        routeMapRef.current = img; scheduleDraw();
      } catch (e) { /* останется рисованный трек */ }
    })();
    return () => { alive = false; };
  }, [open, hasRoute, api, workout?.id, scheduleDraw]);

  const pickPhoto = useCallback(async (source) => {
    const dataUrl = await pickPhotoDataUrl(source);
    if (!dataUrl) return;
    try {
      const img = await loadImage(dataUrl);
      beforePickRef.current = {
        photo: photoRef.current, offset: { ...offsetRef.current }, zoom, bgMode, hasPhoto,
      };
      photoRef.current = img;
      offsetRef.current = { x: 0, y: 0 };
      setZoom(1); setHasPhoto(true); setBgMode('photo');
      setCropOpen(true);
    } catch (e) { /* ignore */ }
  }, [zoom, bgMode, hasPhoto]);

  const templateList = useMemo(
    () => TEMPLATE_ORDER.filter((k) => !(TEMPLATES[k].needsRoute && !hasRoute)),
    [hasRoute],
  );
  const commitSwipe = useCallback((dir, dx) => {
    const canvas = canvasRef.current; const ghost = ghostRef.current;
    if (templateList.length < 2 || !canvas) { if (canvas) canvas.style.transform = ''; return; }
    tapHaptic();
    const fw = canvas.getBoundingClientRect().width || 320;
    const ease = 'cubic-bezier(0.33, 0, 0.2, 1)';
    const dur = Math.max(140, Math.round(300 * ((fw - Math.abs(dx)) / fw)));
    if (ghost && typeof canvas.animate === 'function') {
      try {
        ghost.src = canvas.toDataURL('image/jpeg', 0.85);
        ghost.style.display = 'block';
        const a = ghost.animate(
          [{ transform: `translateX(${dx}px)`, opacity: 1 }, { transform: `translateX(${-dir * fw}px)`, opacity: 0 }],
          { duration: dur, easing: ease },
        );
        a.onfinish = () => { ghost.style.display = 'none'; ghost.removeAttribute('src'); };
        canvas.animate(
          [{ transform: `translateX(${dx + dir * fw}px)` }, { transform: 'translateX(0)' }],
          { duration: dur, easing: ease },
        );
      } catch (e) { /* без анимации */ }
    }
    canvas.style.transform = '';
    setTemplate((cur) => {
      const norm = (cur === 'trace' && !hasRoute) ? 'card' : cur;
      const i = templateList.indexOf(norm);
      return templateList[(i + dir + templateList.length) % templateList.length];
    });
  }, [templateList, hasRoute]);

  const onPointerDown = useCallback((e) => {
    gestureRef.current = { x: e.clientX, y: e.clientY, drag: false, done: false };
    e.currentTarget.setPointerCapture?.(e.pointerId);
  }, []);

  const onPointerMove = useCallback((e) => {
    const g = gestureRef.current;
    if (!g || g.done) return;
    const dx = e.clientX - g.x;
    const dy = e.clientY - g.y;
    if (!g.drag) {
      if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return;
      if (Math.abs(dx) <= Math.abs(dy) * 1.2) { g.done = true; return; }
      g.drag = true;
    }
    const canvas = canvasRef.current;
    if (canvas) canvas.style.transform = `translateX(${dx}px)`;
  }, []);

  const onPointerUp = useCallback((e) => {
    const g = gestureRef.current;
    gestureRef.current = null;
    const canvas = canvasRef.current;
    if (!g || !g.drag || !canvas) { if (canvas) canvas.style.transform = ''; return; }
    const dx = e.clientX - g.x;
    if (Math.abs(dx) > 60) {
      commitSwipe(dx < 0 ? 1 : -1, dx);
    } else {
      if (typeof canvas.animate === 'function') {
        canvas.animate([{ transform: `translateX(${dx}px)` }, { transform: 'translateX(0)' }], { duration: 200, easing: 'cubic-bezier(0.34, 1.2, 0.64, 1)' });
      }
      canvas.style.transform = '';
    }
  }, [commitSwipe]);

  // ── редактор кадрирования фото ──
  const drawCrop = useCallback(() => {
    const canvas = cropCanvasRef.current;
    const photo = photoRef.current;
    if (!canvas || !photo) return;
    const w = EXPORT_W; const h = ASPECTS[aspect].h;
    if (canvas.width !== w) canvas.width = w;
    if (canvas.height !== h) canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, w, h);
    const { drawW, drawH } = coverParams(photo, w, h, zoom);
    let dx = (w - drawW) / 2 + offsetRef.current.x;
    let dy = (h - drawH) / 2 + offsetRef.current.y;
    dx = Math.min(0, Math.max(w - drawW, dx));
    dy = Math.min(0, Math.max(h - drawH, dy));
    offsetRef.current = { x: dx - (w - drawW) / 2, y: dy - (h - drawH) / 2 };
    ctx.drawImage(photo, dx, dy, drawW, drawH);
    ctx.strokeStyle = 'rgba(255,255,255,0.3)'; ctx.lineWidth = 2;
    for (let i = 1; i < 3; i += 1) {
      ctx.beginPath(); ctx.moveTo((w * i) / 3, 0); ctx.lineTo((w * i) / 3, h); ctx.stroke();
      ctx.beginPath(); ctx.moveTo(0, (h * i) / 3); ctx.lineTo(w, (h * i) / 3); ctx.stroke();
    }
  }, [aspect, zoom]);

  useEffect(() => { if (cropOpen) drawCrop(); }, [cropOpen, zoom, aspect, drawCrop]);

  const cropBufScale = useCallback(() => {
    const c = cropCanvasRef.current;
    if (!c) return 1;
    const r = c.getBoundingClientRect();
    return r.width ? c.width / r.width : 1;
  }, []);

  const onCropDown = useCallback((e) => {
    cropDragRef.current = { x: e.clientX, y: e.clientY, ox: offsetRef.current.x, oy: offsetRef.current.y };
    e.currentTarget.setPointerCapture?.(e.pointerId);
  }, []);

  const onCropMove = useCallback((e) => {
    const g = cropDragRef.current;
    if (!g) return;
    const s = cropBufScale();
    offsetRef.current = { x: g.ox + (e.clientX - g.x) * s, y: g.oy + (e.clientY - g.y) * s };
    drawCrop();
  }, [cropBufScale, drawCrop]);

  const onCropUp = useCallback(() => { cropDragRef.current = null; }, []);

  const editCrop = useCallback(() => {
    beforePickRef.current = {
      photo: photoRef.current, offset: { ...offsetRef.current }, zoom, bgMode, hasPhoto,
    };
    setCropOpen(true);
  }, [zoom, bgMode, hasPhoto]);

  const confirmCrop = useCallback(() => { setCropOpen(false); scheduleDraw(); }, [scheduleDraw]);

  const cancelCrop = useCallback(() => {
    const b = beforePickRef.current;
    if (b) {
      photoRef.current = b.photo;
      offsetRef.current = b.offset;
      setZoom(b.zoom); setHasPhoto(b.hasPhoto); setBgMode(b.bgMode);
    }
    setCropOpen(false);
    scheduleDraw();
  }, [scheduleDraw]);

  const toggleMetric = useCallback((key) => {
    tapHaptic();
    setSelected((prev) => {
      if (prev.includes(key)) return prev.length <= 1 ? prev : prev.filter((k) => k !== key);
      return prev.length >= 8 ? prev : [...prev, key];
    });
  }, []);

  const buildFileName = useCallback(() => {
    const t = (model?.typeLabel || 'workout').toLowerCase().replace(/\s+/g, '-');
    return `planrun-${date || 'workout'}-${t}.jpg`;
  }, [model, date]);

  const handleShare = useCallback(async () => {
    if (status === 'sharing') return;
    setStatus('sharing');
    try {
      draw();
      const blob = await canvasToBlob(canvasRef.current, 'image/jpeg', 0.92);
      await shareImageBlob(blob, buildFileName(), 'PlanRun');
    } catch (e) { /* ignore */ } finally { setStatus('idle'); }
  }, [draw, buildFileName, status]);

  const handleSave = useCallback(async () => {
    if (status === 'saving') return;
    setStatus('saving');
    try {
      draw();
      const blob = await canvasToBlob(canvasRef.current, 'image/jpeg', 0.92);
      const res = await saveImageBlob(blob, buildFileName());
      setStatus(res.saved ? 'saved' : 'idle');
      if (res.saved) setTimeout(() => setStatus((s) => (s === 'saved' ? 'idle' : s)), 1800);
    } catch (e) { setStatus('idle'); }
  }, [draw, buildFileName, status]);

  const openPicker = useCallback(() => {
    if (pickerCloseTimer.current) { clearTimeout(pickerCloseTimer.current); pickerCloseTimer.current = null; }
    setPickerClosing(false);
    setColorPickerOpen(true);
  }, []);
  const closePicker = useCallback(() => {
    setPickerClosing(true);
    pickerCloseTimer.current = setTimeout(() => {
      setColorPickerOpen(false);
      setPickerClosing(false);
      pickerCloseTimer.current = null;
    }, 210);
  }, []);
  useEffect(() => () => { if (pickerCloseTimer.current) clearTimeout(pickerCloseTimer.current); }, []);

  if (!open || !model) return null;
  const portalTarget = typeof document !== 'undefined' && (document.getElementById('modal-root') || document.body);
  if (!portalTarget) return null;

  return createPortal(
    <div className="sharecomp-overlay" onClick={onClose}>
      <div className="sharecomp" onClick={(e) => e.stopPropagation()}>
        <div className="sharecomp-head">
          <span className="sharecomp-head-spacer" />
          <div className="sharecomp-aspect">
            {Object.entries(ASPECTS).map(([key, v]) => (
              <button key={key} type="button" className={`sharecomp-chip${aspect === key ? ' is-active' : ''}`} onClick={() => { setAspect(key); tapHaptic(); }}>{v.label}</button>
            ))}
          </div>
          <button type="button" className="sharecomp-close" onClick={onClose} aria-label="Закрыть"><CloseIcon className="modal-close-icon" /></button>
        </div>

        <div className="sharecomp-stage">
          <div className="sharecomp-frame" style={{ aspectRatio: aspect === '9:16' ? '9 / 16' : '4 / 5' }}>
            <canvas
              ref={canvasRef}
              className="sharecomp-canvas"
              style={{ aspectRatio: aspect === '9:16' ? '9 / 16' : '4 / 5' }}
              onPointerDown={onPointerDown}
              onPointerMove={onPointerMove}
              onPointerUp={onPointerUp}
              onPointerCancel={onPointerUp}
            />
            <img ref={ghostRef} className="sharecomp-ghost" alt="" />
            {bgMode === 'map' && bgMapLoading && (
              <div className="sharecomp-loading"><span className="sharecomp-spinner" /></div>
            )}
          </div>
        </div>

        <div className="sharecomp-dots">
          {templateList.map((k) => (
            <span key={k} className={`sharecomp-dot${tmplKey === k ? ' is-active' : ''}`} />
          ))}
        </div>

        <div className="sharecomp-tabs">
          {TABS.map(([key, label]) => (
            <button key={key} type="button" className={`sharecomp-tab${tab === key ? ' is-active' : ''}`} onClick={() => setTab(key)}>{label}</button>
          ))}
        </div>

        <div className={`sharecomp-panel${colorPickerOpen && tab === 'style' ? ' is-picking' : ''}`}>
          {tab === 'bg' && (
            <>
              <div className="sharecomp-row">
                <button type="button" className={`sharecomp-pill${bgMode === 'photo' ? ' is-active' : ''}`} onClick={() => { pickPhoto('prompt'); tapHaptic(); }}><ImageIcon size={15} /> Фото</button>
                {hasPhoto && <button type="button" className="sharecomp-pill" onClick={editCrop}>Кадрировать</button>}
                {hasRoute && <button type="button" className={`sharecomp-pill${bgMode === 'map' ? ' is-active' : ''}`} onClick={() => { setBgMode('map'); tapHaptic(); }}>Карта</button>}
              </div>
              <div className="sharecomp-label">Градиенты</div>
              <div className="sharecomp-row">
                {Object.entries(GRADIENTS).map(([k, g]) => (
                  <button
                    key={k}
                    type="button"
                    className={`sharecomp-grad${bgMode === 'gradient' && gradKey === k ? ' is-active' : ''}`}
                    style={{ background: `linear-gradient(135deg, ${g.stops.join(', ')})` }}
                    onClick={() => { setBgMode('gradient'); setGradKey(k); tapHaptic(); }}
                    title={g.label}
                    aria-label={g.label}
                  />
                ))}
              </div>
            </>
          )}

          {tab === 'data' && (
            <>
              <div className="sharecomp-label">Метрики на карточке</div>
              <div className="sharecomp-chips">
                {model.metrics.map((m) => (
                  <button key={m.key} type="button" className={`sharecomp-mchip${selected.includes(m.key) ? ' is-active' : ''}`} onClick={() => toggleMetric(m.key)}>{m.label}</button>
                ))}
              </div>
              {hasRoute && tmplKey === 'sport' && (
                <>
                  <div className="sharecomp-label">Маршрут</div>
                  <div className="sharecomp-chips">
                    <button type="button" className={`sharecomp-mchip${showRoute ? ' is-active' : ''}`} onClick={() => { setShowRoute((v) => !v); tapHaptic(); }}>Показать трек</button>
                  </div>
                </>
              )}
            </>
          )}

          {tab === 'style' && (
            <>
              <div className="sharecomp-label">Цвет текста</div>
              <div className={`sharecomp-color-swatches${colorPickerOpen && !pickerClosing ? ' is-hidden' : ''}`}>
                <div className="sharecomp-row">
                  <button type="button" className={`sharecomp-swatch sharecomp-swatch--auto${textColor === 'auto' ? ' is-active' : ''}`} onClick={() => { setTextColor('auto'); tapHaptic(); }} title="Авто" aria-label="Авто-контраст" />
                  <button type="button" className={`sharecomp-swatch sharecomp-swatch--pick${(textColor === 'auto' || TEXT_COLORS.includes(textColor)) ? '' : ' is-active'}`} onClick={() => { if (colorPickerOpen) closePicker(); else openPicker(); tapHaptic(); }} title="Свой цвет" aria-label="Выбрать свой цвет" />
                  {TEXT_COLORS.map((c) => (
                    <button key={c} type="button" className={`sharecomp-swatch${textColor === c ? ' is-active' : ''}`} style={{ background: c }} onClick={() => { setTextColor(c); tapHaptic(); }} aria-label={`Цвет ${c}`} />
                  ))}
                </div>
              </div>
              {colorPickerOpen && (
                <div className={`sharecomp-cp-host${pickerClosing ? ' is-closing' : ''}`}>
                  <ColorPicker
                    value={textColor === 'auto' ? '#FFFFFF' : textColor}
                    onChange={setTextColor}
                    onClose={closePicker}
                  />
                </div>
              )}
            </>
          )}
        </div>

        <div className="sharecomp-actions">
          <button type="button" className="btn btn-secondary btn--block" onClick={handleSave} disabled={status === 'saving'}>
            {status === 'saving' ? 'Сохраняем…' : status === 'saved' ? 'Сохранено ✓' : 'Сохранить'}
          </button>
          <button type="button" className="btn btn-primary btn--block" onClick={handleShare} disabled={status === 'sharing'}>
            <ShareIcon size={18} /> {status === 'sharing' ? 'Готовим…' : 'Поделиться'}
          </button>
        </div>

        {cropOpen && hasPhoto && (
          <div className="sharecomp-crop">
            <div className="sharecomp-head">
              <span className="sharecomp-title">Кадрировать</span>
              <div className="sharecomp-aspect">
                {Object.entries(ASPECTS).map(([key, v]) => (
                  <button key={key} type="button" className={`sharecomp-chip${aspect === key ? ' is-active' : ''}`} onClick={() => { setAspect(key); tapHaptic(); }}>{v.label}</button>
                ))}
              </div>
              <button type="button" className="sharecomp-close" onClick={cancelCrop} aria-label="Отмена"><CloseIcon className="modal-close-icon" /></button>
            </div>
            <div className="sharecomp-stage">
              <canvas
                ref={cropCanvasRef}
                className="sharecomp-canvas"
                style={{ aspectRatio: aspect === '9:16' ? '9 / 16' : '4 / 5' }}
                onPointerDown={onCropDown}
                onPointerMove={onCropMove}
                onPointerUp={onCropUp}
                onPointerCancel={onCropUp}
              />
            </div>
            <div className="sharecomp-panel">
              <div className="sharecomp-zoom">
                <ImageIcon size={16} />
                <input type="range" min="1" max="3" step="0.01" value={zoom} onChange={(e) => setZoom(Number(e.target.value))} aria-label="Масштаб" />
              </div>
              <div className="sharecomp-crop-hint">Перетащите и масштабируйте фото в рамке</div>
            </div>
            <div className="sharecomp-actions">
              <button type="button" className="btn btn-secondary btn--block" onClick={cancelCrop}>Отмена</button>
              <button type="button" className="btn btn-primary btn--block" onClick={confirmCrop}>Готово</button>
            </div>
          </div>
        )}
      </div>
    </div>,
    portalTarget,
  );
}
