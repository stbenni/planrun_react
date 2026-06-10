/* Шаблоны рендера карточки шеринга на canvas.
   Каждый шаблон: draw(ctx, w, h, { model, metrics, textColor, accent, routeMapImg }).
   Фон уже нарисован композером; шаблон рисует только оверлей. */

const BRAND = '#FC4C02';
const FF = 'Montserrat, Jost, system-ui, sans-serif';
const FF_LOGO = 'Jost, system-ui, sans-serif';
const MUTED = 'rgba(255,255,255,0.72)';
const HAIRLINE = 'rgba(255,255,255,0.20)';
const PAD = 60;

export function roundRectPath(ctx, x, y, w, h, r) {
  const rad = Math.min(r, w / 2, h / 2);
  ctx.beginPath();
  ctx.moveTo(x + rad, y);
  ctx.arcTo(x + w, y, x + w, y + h, rad);
  ctx.arcTo(x + w, y + h, x, y + h, rad);
  ctx.arcTo(x, y + h, x, y, rad);
  ctx.arcTo(x, y, x + w, y, rad);
  ctx.closePath();
}

export function projectRoute(points, box) {
  if (!points || points.length < 2) return [];
  const step = Math.max(1, Math.floor(points.length / 260));
  const sampled = points.filter((_, i) => i % step === 0 || i === points.length - 1);
  const lats = sampled.map((p) => p.lat);
  const lngs = sampled.map((p) => p.lng);
  const minLat = Math.min(...lats); const maxLat = Math.max(...lats);
  const minLng = Math.min(...lngs); const maxLng = Math.max(...lngs);
  const latR = Math.max(1e-5, maxLat - minLat);
  const lngR = Math.max(1e-5, maxLng - minLng);
  const fit = Math.min(box.w / lngR, box.h / latR);
  const drawW = lngR * fit; const drawH = latR * fit;
  const offX = box.x + (box.w - drawW) / 2;
  const offY = box.y + (box.h - drawH) / 2;
  return sampled.map((p) => ({
    x: offX + ((p.lng - minLng) / lngR) * drawW,
    y: offY + ((maxLat - p.lat) / latR) * drawH,
  }));
}

function shadow(ctx, on) {
  ctx.shadowColor = on ? 'rgba(0,0,0,0.42)' : 'transparent';
  ctx.shadowBlur = on ? 18 : 0;
  ctx.shadowOffsetY = on ? 2 : 0;
}

function track(ctx, v) { try { ctx.letterSpacing = v; } catch (e) { /* старый WebView */ } }

/* Логотип planRUN — крупно, верх-право */
function drawLogo(ctx, w) {
  ctx.save();
  shadow(ctx, true);
  ctx.textBaseline = 'alphabetic'; ctx.textAlign = 'left';
  const size = 87;
  const y = PAD + size;
  const planFont = `italic 300 ${size}px ${FF_LOGO}`;
  const runFont = `italic 800 ${size}px ${FF_LOGO}`;
  ctx.font = planFont; const pw = ctx.measureText('plan').width;
  ctx.font = runFont; const rw = ctx.measureText('RUN').width;
  const startX = (w - PAD) - pw - rw;
  ctx.font = planFont; ctx.fillStyle = '#FFFFFF'; ctx.fillText('plan', startX, y);
  ctx.font = runFont; ctx.fillStyle = BRAND; ctx.fillText('RUN', startX + pw, y);
  ctx.restore();
}

/* Логотип planRUN — компактно, низ-лево */
function drawLogoBottom(ctx, w, h) {
  ctx.save();
  shadow(ctx, true);
  ctx.textBaseline = 'alphabetic'; ctx.textAlign = 'left';
  const size = 54;
  const y = h - PAD;
  const planFont = `italic 300 ${size}px ${FF_LOGO}`;
  const runFont = `italic 800 ${size}px ${FF_LOGO}`;
  ctx.font = planFont; ctx.fillStyle = '#FFFFFF'; ctx.fillText('plan', PAD, y);
  const pw = ctx.measureText('plan').width;
  ctx.font = runFont; ctx.fillStyle = BRAND; ctx.fillText('RUN', PAD + pw, y);
  ctx.restore();
}

/* Эйбрау: акцент-линия + ТИП · ДАТА (трекинг). topY — верх линии. */
function drawEyebrow(ctx, model, x, topY, accent, color) {
  ctx.save();
  shadow(ctx, true);
  ctx.fillStyle = accent;
  ctx.fillRect(x, topY, 64, 6);
  ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic';
  ctx.font = `700 30px ${FF}`;
  track(ctx, '2px');
  const t = String(model.typeLabel || 'Тренировка').toUpperCase();
  ctx.fillStyle = color;
  ctx.fillText(t, x, topY + 52);
  if (model.dateStr) {
    const tw = ctx.measureText(t).width;
    ctx.fillStyle = MUTED;
    ctx.fillText(` · ${String(model.dateStr).toUpperCase()}`, x + tw + 8, topY + 52);
  }
  track(ctx, '0px');
  ctx.restore();
}

/* Гигантский герой-число + юнит, с авто-уменьшением под ширину. */
function drawHero(ctx, metric, x, baseline, color, accent, maxSize, availW) {
  if (!metric) return;
  ctx.save();
  shadow(ctx, true);
  ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic';
  const val = String(metric.value);
  let size = maxSize;
  track(ctx, '-3px');
  ctx.font = `800 ${size}px ${FF}`;
  const unitW = metric.unit ? maxSize * 0.5 : 0;
  while (size > 70 && ctx.measureText(val).width + unitW > availW) { size -= 12; ctx.font = `800 ${size}px ${FF}`; }
  ctx.fillStyle = color;
  ctx.fillText(val, x, baseline);
  const vw = ctx.measureText(val).width;
  track(ctx, '0px');
  if (metric.unit) {
    ctx.font = `700 ${Math.round(size * 0.24)}px ${FF}`;
    ctx.fillStyle = accent;
    ctx.fillText(String(metric.unit).toUpperCase(), x + vw + 18, baseline);
  }
  ctx.restore();
}

/* Ряд статов с тонкими делителями. labelY — baseline подписи. */
function drawStatsStrip(ctx, metrics, x, labelY, totalW, color, maxCols = 3) {
  const list = metrics.slice(0, maxCols);
  if (!list.length) return;
  const colW = totalW / list.length;
  const valSize = list.length >= 4 ? 50 : 60;
  const lblSize = list.length >= 4 ? 21 : 23;
  ctx.save();
  shadow(ctx, true);
  ctx.textBaseline = 'alphabetic'; ctx.textAlign = 'left';
  list.forEach((m, i) => {
    const cx = x + colW * i;
    if (i > 0) {
      ctx.save();
      ctx.strokeStyle = HAIRLINE; ctx.lineWidth = 2;
      ctx.beginPath(); ctx.moveTo(cx - 22, labelY - 22); ctx.lineTo(cx - 22, labelY + 50); ctx.stroke();
      ctx.restore();
    }
    ctx.font = `600 ${lblSize}px ${FF}`;
    track(ctx, '1.2px');
    ctx.fillStyle = MUTED;
    ctx.fillText(String(m.label).toUpperCase(), cx, labelY);
    track(ctx, '0px');
    ctx.font = `700 ${valSize}px ${FF}`;
    ctx.fillStyle = color;
    ctx.fillText(String(m.value), cx, labelY + 62);
    if (m.unit) {
      const vw = ctx.measureText(String(m.value)).width;
      ctx.font = `600 22px ${FF}`;
      ctx.fillStyle = 'rgba(255,255,255,0.8)';
      ctx.fillText(m.unit, cx + vw + 7, labelY + 62);
    }
  });
  ctx.restore();
}

function drawHairline(ctx, x, y, w2) {
  ctx.save();
  shadow(ctx, true);
  ctx.strokeStyle = HAIRLINE; ctx.lineWidth = 2;
  ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(x + w2, y); ctx.stroke();
  ctx.restore();
}

function drawTrace(ctx, opts, box, lineW = 10) {
  const { model, textColor, accent } = opts;
  const pts = projectRoute(model.routePoints, box);
  if (pts.length < 2) return null;
  ctx.save();
  shadow(ctx, true);
  ctx.lineJoin = 'round'; ctx.lineCap = 'round';
  ctx.strokeStyle = textColor; ctx.lineWidth = lineW;
  ctx.beginPath();
  pts.forEach((p, i) => (i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y)));
  ctx.stroke();
  ctx.restore();
  const s = pts[0];
  ctx.save();
  ctx.fillStyle = accent; ctx.strokeStyle = '#fff'; ctx.lineWidth = 5;
  ctx.beginPath(); ctx.arc(s.x, s.y, 15, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
  ctx.restore();
  return pts;
}

/* ── Минимал: эйбрау + гигантский герой + линия + ряд статов ── */
function tmplMinimal(ctx, w, h, opts) {
  const { model, metrics, textColor, accent } = opts;
  const hero = metrics[0] || model.metrics[0];
  const rest = metrics.slice(1, 4);
  const startY = h - 600;
  drawEyebrow(ctx, model, PAD, startY, accent, textColor);
  const heroBaseline = startY + 320;
  drawHero(ctx, hero, PAD, heroBaseline, textColor, accent, 280, w - PAD * 2);
  if (rest.length) {
    const ruleY = heroBaseline + 46;
    drawHairline(ctx, PAD, ruleY, w - PAD * 2);
    drawStatsStrip(ctx, rest, PAD, ruleY + 58, w - PAD * 2, textColor);
  }
  drawLogo(ctx, w);
}

/* ── Трек: линия маршрута поверх фото + эйбрау + статы ── */
function tmplTrace(ctx, w, h, opts) {
  const { model, metrics, textColor, accent } = opts;
  const box = { x: PAD + 20, y: h * 0.17, w: w - (PAD + 20) * 2, h: h * 0.30 };
  const pts = drawTrace(ctx, opts, box, 9);
  const distM = model.metrics.find((m) => m.key === 'distance');
  if (pts && distM) {
    ctx.save();
    shadow(ctx, true);
    ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
    ctx.font = `700 36px ${FF}`;
    track(ctx, '1px');
    ctx.fillStyle = textColor;
    ctx.fillText(`${distM.value} км`, box.x + box.w / 2, box.y - 28);
    track(ctx, '0px');
    ctx.restore();
  }
  const startY = h - 300;
  drawEyebrow(ctx, model, PAD, startY, accent, textColor);
  drawHairline(ctx, PAD, startY + 86, w - PAD * 2);
  drawStatsStrip(ctx, metrics, PAD, startY + 144, w - PAD * 2, textColor, 4);
  drawLogo(ctx, w);
}

/* Сетка метрик: cols колонок, label сверху / value снизу. */
function drawMetricGrid(ctx, list, x, y, totalW, color, cols = 3, rowH = 100) {
  if (!list.length) return;
  const colW = totalW / cols;
  ctx.save();
  ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic';
  list.forEach((m, i) => {
    const cx = x + colW * (i % cols);
    const cy = y + Math.floor(i / cols) * rowH;
    ctx.font = `600 21px ${FF}`; track(ctx, '1px'); ctx.fillStyle = MUTED;
    ctx.fillText(String(m.label).toUpperCase(), cx, cy); track(ctx, '0px');
    ctx.font = `700 46px ${FF}`; ctx.fillStyle = color;
    ctx.fillText(String(m.value), cx, cy + 50);
    if (m.unit) {
      const vw = ctx.measureText(String(m.value)).width;
      ctx.font = `600 21px ${FF}`; ctx.fillStyle = 'rgba(255,255,255,0.8)';
      ctx.fillText(m.unit, cx + vw + 7, cy + 50);
    }
  });
  ctx.restore();
}

/* ── Карточка: glass + большая карта-баннер + все метрики ── */
function tmplCard(ctx, w, h, opts) {
  const { model, metrics, textColor, accent, routeMapImg } = opts;
  const all = (metrics && metrics.length) ? metrics : model.metrics;
  const hero = all[0];
  const rest = all.slice(1);
  const showMap = !!routeMapImg;

  const cw = w - PAD * 2;
  const innerPad = 44;
  const ix = PAD + innerPad;
  const iw = cw - innerPad * 2;
  const eyebrowH = 44;
  const mapH = showMap ? 330 : 0;
  const heroH = 128;
  const cols = 3;
  const gridRows = rest.length ? Math.ceil(rest.length / cols) : 0;
  const gridRowH = 100;
  const gridH = gridRows ? ((gridRows - 1) * gridRowH + 70) : 0;
  const g = 24;
  const ch = innerPad * 2 + eyebrowH + g
    + (showMap ? mapH + g : 0) + heroH + (gridRows ? g + gridH : 0);
  const cx = PAD; const cy = h - PAD - ch; const radius = 40;

  const canvas = ctx.canvas;
  ctx.save();
  roundRectPath(ctx, cx, cy, cw, ch, radius); ctx.clip();
  try { ctx.filter = 'blur(28px)'; ctx.drawImage(canvas, 0, 0); ctx.filter = 'none'; } catch (e) { /* нет filter */ }
  ctx.fillStyle = 'rgba(15,19,27,0.52)'; ctx.fillRect(cx, cy, cw, ch);
  ctx.restore();
  ctx.save();
  roundRectPath(ctx, cx, cy, cw, ch, radius);
  ctx.lineWidth = 1.5; ctx.strokeStyle = 'rgba(255,255,255,0.22)'; ctx.stroke();
  ctx.restore();

  let yy = cy + innerPad;
  ctx.textBaseline = 'middle'; ctx.textAlign = 'left';
  ctx.font = `700 24px ${FF}`; track(ctx, '1px');
  const typeText = String(model.typeLabel || 'Тренировка').toUpperCase();
  const tw = ctx.measureText(typeText).width;
  roundRectPath(ctx, ix, yy, tw + 36, eyebrowH, eyebrowH / 2);
  ctx.fillStyle = 'rgba(255,107,44,0.18)'; ctx.fill();
  ctx.fillStyle = accent; ctx.fillText(typeText, ix + 18, yy + eyebrowH / 2 + 1); track(ctx, '0px');
  if (model.dateStr) {
    ctx.font = `600 26px ${FF}`; ctx.fillStyle = MUTED; ctx.textAlign = 'right';
    ctx.fillText(model.dateStr, ix + iw, yy + eyebrowH / 2 + 1);
  }
  yy += eyebrowH + g;

  if (showMap) {
    ctx.save();
    roundRectPath(ctx, ix, yy, iw, mapH, 24); ctx.clip();
    const ms = Math.min(iw / routeMapImg.width, mapH / routeMapImg.height);
    const mw = routeMapImg.width * ms; const mh = routeMapImg.height * ms;
    ctx.drawImage(routeMapImg, ix + (iw - mw) / 2, yy + (mapH - mh) / 2, mw, mh);
    ctx.restore();
    ctx.save();
    roundRectPath(ctx, ix, yy, iw, mapH, 24);
    ctx.lineWidth = 1.5; ctx.strokeStyle = 'rgba(255,255,255,0.2)'; ctx.stroke();
    ctx.restore();
    yy += mapH + g;
  }

  drawHero(ctx, hero, ix, yy + heroH - 22, textColor, accent, 122, iw);
  yy += heroH;

  if (gridRows) {
    yy += g;
    drawMetricGrid(ctx, rest, ix, yy + 22, iw, textColor, cols, gridRowH);
  }

  drawLogo(ctx, w);
}

/* ── Спорт: метрики плотной стопкой слева-сверху, дистанция крупнее ── */
function tmplSport(ctx, w, h, opts) {
  const { model, metrics, textColor, accent } = opts;
  const list = (metrics && metrics.length ? metrics : model.metrics).slice(0, 5);
  drawLogoBottom(ctx, w, h);
  if (!list.length) return;

  const availW = w - PAD * 2;
  const unitFactor = 0.32;
  const SX = 0.86; // горизонтальное сжатие значений → узкий «спортивный» гротеск
  let rest = 116;
  let hero = Math.round(rest * 1.32);
  const sizeOf = (i) => (i === 0 ? hero : rest);

  const widest = () => {
    let m = 0;
    list.forEach((it, i) => {
      const vs = sizeOf(i);
      ctx.font = `800 ${vs}px ${FF}`;
      let vw = ctx.measureText(String(it.value)).width * SX;
      if (it.unit) { ctx.font = `800 ${Math.round(vs * unitFactor)}px ${FF}`; vw += 16 + ctx.measureText(String(it.unit).toUpperCase()).width; }
      if (vw > m) m = vw;
    });
    return m;
  };
  while (rest > 70 && widest() > availW) { rest -= 6; hero = Math.round(rest * 1.32); }

  ctx.save();
  shadow(ctx, true);
  ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic';

  let y = PAD + 120 + hero * 0.72;
  list.forEach((it, i) => {
    const vs = sizeOf(i);
    ctx.font = `800 ${vs}px ${FF}`; track(ctx, '-1px');
    ctx.fillStyle = textColor;
    const vw = ctx.measureText(String(it.value)).width * SX;
    ctx.save();
    ctx.translate(PAD, y); ctx.scale(SX, 1);
    ctx.fillText(String(it.value), 0, 0);
    ctx.restore();
    track(ctx, '0px');
    if (it.unit) {
      ctx.font = `800 ${Math.round(vs * unitFactor)}px ${FF}`;
      ctx.fillStyle = accent;
      ctx.fillText(String(it.unit).toUpperCase(), PAD + vw + 16, y);
    }
    if (i < list.length - 1) y += vs * 0.3 + sizeOf(i + 1) * 0.72;
  });

  const lastVs = sizeOf(list.length - 1);
  const typeY = y + lastVs * 0.28 + 70;
  ctx.font = `600 32px ${FF}`; ctx.fillStyle = MUTED;
  ctx.fillText(String(model.typeLabel || 'Тренировка'), PAD, typeY);
  if (model.dateStr) {
    ctx.font = `700 34px ${FF}`; ctx.fillStyle = textColor;
    ctx.fillText(String(model.dateStr), PAD, typeY + 46);
  }
  ctx.restore();

  if (opts.showRoute !== false && (model.routePoints?.length || 0) >= 2) {
    const boxH = Math.min(320, Math.round(h * 0.18));
    const boxW = Math.min(440, Math.round(availW * 0.52));
    const box = { x: w - PAD - boxW, y: h - PAD - boxH, w: boxW, h: boxH };
    drawTrace(ctx, opts, box, 7);
  }
}

export const TEMPLATES = {
  minimal: { label: 'Минимал', draw: tmplMinimal, needsRoute: false },
  sport: { label: 'Спорт', draw: tmplSport, needsRoute: false },
  card: { label: 'Карточка', draw: tmplCard, needsRoute: false },
  trace: { label: 'Трек', draw: tmplTrace, needsRoute: true },
};

export const TEMPLATE_ORDER = ['minimal', 'sport', 'card', 'trace'];
