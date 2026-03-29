#!/usr/bin/env node

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const repoRoot = path.resolve(__dirname, '../..');

function getArg(name) {
  const index = process.argv.indexOf(name);
  if (index === -1 || index === process.argv.length - 1) {
    return null;
  }
  return process.argv[index + 1];
}

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

function normalizeText(value, fallback = '—') {
  const normalized = String(value ?? '').trim();
  return normalized === '' ? fallback : normalized;
}

function getRoutePoints(timeline) {
  if (!Array.isArray(timeline)) return [];
  return timeline
    .map((point) => ({
      latitude: Number(point?.latitude),
      longitude: Number(point?.longitude),
    }))
    .filter((point) => Number.isFinite(point.latitude) && Number.isFinite(point.longitude));
}

function buildFallbackRouteSvg(timeline, width = 364, height = 236) {
  const points = getRoutePoints(timeline);
  if (points.length < 2) {
    return '';
  }

  const sampleStep = Math.max(1, Math.floor(points.length / 180));
  const sampled = points.filter((_, index) => index % sampleStep === 0 || index === points.length - 1);
  const latitudes = sampled.map((point) => point.latitude);
  const longitudes = sampled.map((point) => point.longitude);
  const minLat = Math.min(...latitudes);
  const maxLat = Math.max(...latitudes);
  const minLng = Math.min(...longitudes);
  const maxLng = Math.max(...longitudes);
  const latRange = Math.max(0.0001, maxLat - minLat);
  const lngRange = Math.max(0.0001, maxLng - minLng);
  const padding = 18;
  const drawableWidth = width - padding * 2;
  const drawableHeight = height - padding * 2;

  const projected = sampled.map((point) => ({
    x: padding + ((point.longitude - minLng) / lngRange) * drawableWidth,
    y: padding + drawableHeight - ((point.latitude - minLat) / latRange) * drawableHeight,
  }));

  const pathData = projected
    .map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x.toFixed(2)} ${point.y.toFixed(2)}`)
    .join(' ');

  const startPoint = projected[0];
  const endPoint = projected[projected.length - 1];

  return `
    <svg viewBox="0 0 ${width} ${height}" class="route-fallback-svg" role="img" aria-label="Маршрут тренировки">
      <defs>
        <linearGradient id="share-route-line" x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" stop-color="#FDBA74" />
          <stop offset="52%" stop-color="#FB923C" />
          <stop offset="100%" stop-color="#F97316" />
        </linearGradient>
      </defs>
      <line x1="18" y1="${padding + drawableHeight * 0.2}" x2="${width - 18}" y2="${padding + drawableHeight * 0.2}" stroke="rgba(148,163,184,0.14)" stroke-width="1" />
      <line x1="18" y1="${padding + drawableHeight * 0.5}" x2="${width - 18}" y2="${padding + drawableHeight * 0.5}" stroke="rgba(148,163,184,0.14)" stroke-width="1" />
      <line x1="18" y1="${padding + drawableHeight * 0.8}" x2="${width - 18}" y2="${padding + drawableHeight * 0.8}" stroke="rgba(148,163,184,0.14)" stroke-width="1" />
      <line x1="${padding + drawableWidth * 0.25}" y1="18" x2="${padding + drawableWidth * 0.25}" y2="${height - 18}" stroke="rgba(148,163,184,0.08)" stroke-width="1" />
      <line x1="${padding + drawableWidth * 0.5}" y1="18" x2="${padding + drawableWidth * 0.5}" y2="${height - 18}" stroke="rgba(148,163,184,0.08)" stroke-width="1" />
      <line x1="${padding + drawableWidth * 0.75}" y1="18" x2="${padding + drawableWidth * 0.75}" y2="${height - 18}" stroke="rgba(148,163,184,0.08)" stroke-width="1" />
      <path d="M ${padding} ${padding + 28} C ${padding + 46} ${padding + 6}, ${padding + 102} ${padding + 44}, ${width - padding} ${padding + 18}" fill="none" stroke="rgba(226,232,240,0.12)" stroke-width="1" stroke-linecap="round" />
      <path d="M ${padding + 18} ${height * 0.56} C ${width * 0.28} ${height * 0.42}, ${width * 0.58} ${height * 0.74}, ${width - padding} ${height * 0.58}" fill="none" stroke="rgba(226,232,240,0.12)" stroke-width="1.25" stroke-linecap="round" />
      <path d="M ${width * 0.18} ${height - padding - 18} C ${width * 0.22} ${height * 0.62}, ${width * 0.44} ${height * 0.76}, ${width * 0.66} ${height - padding - 12}" fill="none" stroke="rgba(226,232,240,0.12)" stroke-width="1" stroke-linecap="round" />
      <path d="${pathData}" fill="none" stroke="rgba(249,115,22,0.34)" stroke-width="14" stroke-linecap="round" stroke-linejoin="round" />
      <path d="${pathData}" fill="none" stroke="rgba(255,255,255,0.22)" stroke-width="8" stroke-linecap="round" stroke-linejoin="round" opacity="0.28" />
      <path d="${pathData}" fill="none" stroke="url(#share-route-line)" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round" />
      <circle cx="${startPoint.x}" cy="${startPoint.y}" r="7.5" fill="#FFFFFF" stroke="#F97316" stroke-width="3" />
      <circle cx="${endPoint.x}" cy="${endPoint.y}" r="7.5" fill="#F97316" stroke="#111827" stroke-width="3" />
    </svg>
  `;
}

function renderWordmark() {
  return `
    <div class="brand-wordmark">
      <span class="logo-text"><span class="logo-plan">plan</span><span class="logo-run">RUN</span></span>
    </div>
  `;
}

function renderBadge(text, muted = false) {
  if (!text) return '';
  return `<span class="share-badge${muted ? ' share-badge--muted' : ''}">${escapeHtml(text)}</span>`;
}

function renderRouteMap(card, timeline) {
  const staticMapUrl = card.staticMapDataUrl || null;
  const fallbackSvg = !staticMapUrl ? buildFallbackRouteSvg(timeline) : '';
  return `
    <div class="route-map-block">
      <div class="route-map">
        ${staticMapUrl ? `<img class="route-map__image" src="${staticMapUrl}" alt="Маршрут тренировки на карте" />` : `<div class="route-map__fallback">${fallbackSvg}</div>`}
        <div class="route-map__overlay"></div>
        <div class="route-map__top">
          <span class="map-chip map-chip--light">Маршрут</span>
          <span class="map-chip map-chip--dark">GPS</span>
        </div>
      </div>
    </div>
  `;
}

function renderMetricTile(label, primary, secondary, accent = false) {
  return `
    <div class="metric-tile${accent ? ' metric-tile--accent' : ''}">
      <div class="metric-tile__label">${escapeHtml(label)}</div>
      <div class="metric-tile__primary">${escapeHtml(primary)}</div>
      <div class="metric-tile__secondary">${escapeHtml(secondary)}</div>
    </div>
  `;
}

function renderRouteCard(card, timeline) {
  const distance = card.distance;
  const note = card.notes ? `
    <div class="share-note">
      ${escapeHtml(card.notes)}
    </div>
  ` : '';
  const attribution = card.staticMapDataUrl && card.mapAttribution ? `
    <div class="share-attribution">${escapeHtml(card.mapAttribution)}</div>
  ` : '';

  return `
    <div id="share-card-root" class="share-card share-card--route">
      <div class="share-card__glow"></div>

      <div class="share-card__header">
        <div>
          ${renderWordmark()}
          <div class="badge-row">
            ${renderBadge(card.typeLabel)}
            ${card.sourceLabel ? renderBadge(card.sourceLabel, true) : ''}
          </div>
        </div>
        <div class="share-card__date">
          <div class="share-card__date-label">${escapeHtml(card.dateLabel)}</div>
          <div class="share-card__time">${escapeHtml(card.startTimeLabel)}</div>
        </div>
      </div>

      <div class="route-hero">
        <div>
          ${distance ? `
            <div class="distance-hero">
              <span class="distance-hero__value">${escapeHtml(distance.value)}</span>
              <span class="distance-hero__unit">${escapeHtml(distance.unit)}</span>
            </div>
          ` : `
            <div class="distance-hero distance-hero--duration-only">
              <span class="distance-hero__duration">${escapeHtml(normalizeText(card.durationValue))}</span>
            </div>
          `}
        </div>
        <div class="hero-tile">
          <div class="hero-tile__label">Время</div>
          <div class="hero-tile__value">${escapeHtml(normalizeText(card.durationValue))}</div>
        </div>
      </div>

      ${renderRouteMap(card, timeline)}

      <div class="metric-grid metric-grid--route">
        ${renderMetricTile('Темп', normalizeText(card.paceValue), 'МИН/КМ', true)}
        ${renderMetricTile('Пульс', normalizeText(card.pulseValue), 'УД/МИН')}
        ${renderMetricTile('Высота', normalizeText(card.elevationValue), 'М')}
      </div>

      ${note}
      ${attribution}
    </div>
  `;
}

function renderMinimalRow(label, value) {
  return `
    <div class="minimal-row">
      <span class="minimal-row__label">${escapeHtml(label)}</span>
      <span class="minimal-row__value">${escapeHtml(value)}</span>
    </div>
  `;
}

function renderMinimalCard(card, timeline) {
  const distance = card.distance;
  const summaryRows = [
    ['Тип', card.typeLabel],
    ['Старт', `${card.dateLabel}${card.startTimeLabel && card.startTimeLabel !== '—' ? `, ${card.startTimeLabel}` : ''}`],
    ['Источник', card.sourceLabel || 'PlanRun'],
    ['Время', normalizeText(card.durationValue)],
    ['Темп', `${normalizeText(card.paceValue)} /км`],
    ['Пульс', `${normalizeText(card.pulseValue)} уд/мин`],
  ];
  const note = card.notes ? `<div class="minimal-note">${escapeHtml(card.notes)}</div>` : '';

  return `
    <div id="share-card-root" class="share-card share-card--minimal">
      <div class="minimal-header">
        <div>
          ${renderWordmark()}
        </div>
        <div class="minimal-header__meta">
          <div class="minimal-header__date">${escapeHtml(card.dateLabel)}</div>
          <div class="minimal-header__time">${escapeHtml(card.startTimeLabel)}</div>
        </div>
      </div>

      <div class="minimal-hero">
        ${distance ? `
          <div class="distance-hero distance-hero--minimal">
            <span class="distance-hero__value minimal-distance">${escapeHtml(distance.value)}</span>
            <span class="distance-hero__unit minimal-distance-unit">${escapeHtml(distance.unit)}</span>
          </div>
        ` : `
          <div class="distance-hero distance-hero--duration-only">
            <span class="distance-hero__duration">${escapeHtml(normalizeText(card.durationValue))}</span>
          </div>
        `}
        <div class="minimal-hero__subtitle">${escapeHtml(card.paceValue && card.paceValue !== '—' ? `Средний темп ${card.paceValue} /км` : card.typeLabel)}</div>
      </div>

      <div class="minimal-table">
        ${summaryRows.map(([label, value]) => renderMinimalRow(label, value)).join('')}
      </div>

      ${note}
    </div>
  `;
}

async function loadFontData(fileName) {
  const fontPath = path.join(repoRoot, 'public', 'fonts', fileName);
  const buffer = await fs.readFile(fontPath);
  return `data:font/woff2;base64,${buffer.toString('base64')}`;
}

async function renderDocument(payload) {
  const normalFont = await loadFontData('jost-latin.woff2');
  const italicFont = await loadFontData('jost-italic-latin.woff2');
  const card = payload.card || {};
  const timeline = Array.isArray(payload.timeline) ? payload.timeline : [];
  const template = payload.template === 'minimal' ? 'minimal' : 'route';
  const bodyHtml = template === 'minimal'
    ? renderMinimalCard(card, timeline)
    : renderRouteCard(card, timeline);

  return `
    <!doctype html>
    <html lang="ru">
      <head>
        <meta charset="utf-8" />
        <style>
          @font-face {
            font-family: 'Jost';
            font-style: normal;
            font-weight: 300 800;
            font-display: swap;
            src: url('${normalFont}') format('woff2');
          }
          @font-face {
            font-family: 'Jost';
            font-style: italic;
            font-weight: 300 800;
            font-display: swap;
            src: url('${italicFont}') format('woff2');
          }
          * { box-sizing: border-box; }
          html, body { margin: 0; padding: 0; background: transparent; }
          body {
            font-family: 'Jost', system-ui, sans-serif;
            color: #0F172A;
          }
          #capture-shell {
            padding: 14px;
            display: inline-block;
          }
          .share-card {
            width: 420px;
            position: relative;
            overflow: hidden;
            border-radius: 30px;
          }
          .share-card--route {
            background: linear-gradient(180deg, #FFF7F1 0%, #FFFDFC 38%, #FFFFFF 100%);
            border: 1px solid rgba(252, 76, 2, 0.14);
            box-shadow: 0 28px 66px rgba(15, 23, 42, 0.14);
            padding: 28px;
          }
          .share-card--minimal {
            background: #FFFFFF;
            border: 1px solid rgba(226, 232, 240, 0.95);
            box-shadow: 0 18px 44px rgba(15, 23, 42, 0.08);
            border-radius: 26px;
            padding: 28px;
          }
          .share-card__glow {
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top right, rgba(252,76,2,0.14) 0%, rgba(252,76,2,0.04) 22%, rgba(252,76,2,0) 48%);
            pointer-events: none;
          }
          .share-card__header, .minimal-header {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
          }
          .share-card__header { margin-bottom: 18px; }
          .minimal-header { margin-bottom: 24px; gap: 20px; }
          .brand-wordmark { margin-bottom: 10px; }
          .logo-text {
            font-size: 24px;
            font-style: italic;
            color: #0F172A;
            letter-spacing: -0.5px;
            line-height: 1;
          }
          .logo-plan { font-weight: 300; color: #0F172A; }
          .logo-run { font-weight: 800; color: #F97316; }
          .badge-row { display: flex; flex-wrap: wrap; gap: 8px; }
          .share-badge {
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(252,76,2,0.10);
            color: #EA580C;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            line-height: 1;
          }
          .share-badge--muted {
            background: rgba(15,23,42,0.06);
            color: #475569;
            font-weight: 600;
            letter-spacing: 0.02em;
            text-transform: none;
          }
          .share-card__date { text-align: right; }
          .share-card__date-label {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #94A3B8;
            margin-bottom: 6px;
          }
          .share-card__time, .minimal-header__time { font-size: 14px; color: #64748B; }
          .minimal-header__meta { text-align: right; }
          .minimal-header__date { font-size: 15px; font-weight: 700; color: #0F172A; line-height: 1.2; }
          .minimal-subtitle {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #94A3B8;
          }
          .route-hero {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 16px;
            align-items: end;
            margin-bottom: 8px;
          }
          .distance-hero {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            line-height: 0.92;
          }
          .distance-hero__value {
            font-size: 78px;
            font-weight: 800;
            font-style: italic;
            letter-spacing: -0.07em;
            color: #F97316;
          }
          .distance-hero__unit {
            font-size: 24px;
            font-weight: 800;
            color: #0F172A;
            padding-bottom: 10px;
          }
          .distance-hero__duration {
            font-size: 46px;
            font-weight: 800;
            letter-spacing: -0.05em;
            color: #0F172A;
          }
          .hero-tile {
            border-radius: 24px;
            background: rgba(255,255,255,0.94);
            border: 1px solid rgba(252,76,2,0.12);
            padding: 16px 16px 15px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.78);
          }
          .hero-tile__label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #94A3B8;
            margin-bottom: 8px;
          }
          .hero-tile__value {
            font-size: 28px;
            font-weight: 700;
            line-height: 1;
            color: #0F172A;
          }
          .route-map-block {
            position: relative;
            z-index: 1;
            margin-top: 16px;
          }
          .route-map {
            position: relative;
            height: 236px;
            border-radius: 20px;
            overflow: hidden;
            background: #0F172A;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.16);
          }
          .route-map__image,
          .route-map__fallback {
            display: block;
            width: 100%;
            height: 100%;
          }
          .route-map__image {
            object-fit: cover;
            transform: scale(1.015);
          }
          .route-fallback-svg {
            width: 100%;
            height: 100%;
            display: block;
            background: linear-gradient(180deg, #151A23 0%, #1A2230 56%, #141923 100%);
          }
          .route-map__overlay {
            position: absolute;
            inset: 0;
            background:
              linear-gradient(180deg, rgba(15,23,42,0.02) 0%, rgba(15,23,42,0) 20%, rgba(15,23,42,0.10) 100%),
              linear-gradient(0deg, rgba(15,23,42,0.48) 0%, rgba(15,23,42,0.10) 18%, rgba(15,23,42,0) 36%),
              radial-gradient(circle at top right, rgba(252,76,2,0.18) 0%, rgba(252,76,2,0.06) 18%, rgba(252,76,2,0) 42%);
            pointer-events: none;
          }
          .route-map__top {
            position: absolute;
            top: 12px;
            left: 12px;
            right: 12px;
            display: flex;
            justify-content: space-between;
            gap: 10px;
          }
          .map-chip {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 0 11px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
          }
          .map-chip--light {
            background: rgba(255,255,255,0.82);
            border: 1px solid rgba(255,255,255,0.56);
            color: #EA580C;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12);
          }
          .map-chip--dark {
            background: rgba(15,23,42,0.56);
            border: 1px solid rgba(255,255,255,0.12);
            color: #E2E8F0;
          }
          .metric-grid {
            position: relative;
            z-index: 1;
            display: grid;
            gap: 12px;
          }
          .metric-grid--route {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            margin-top: 16px;
          }
          .metric-tile {
            padding: 14px 16px;
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.16);
            background: rgba(255,255,255,0.94);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.82);
          }
          .metric-tile--accent {
            border-color: rgba(252, 76, 2, 0.16);
            background: rgba(255, 249, 245, 0.98);
          }
          .metric-tile__label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #94A3B8;
            margin-bottom: 6px;
          }
          .metric-tile--accent .metric-tile__label { color: #F97316; }
          .metric-tile__primary {
            font-size: 17px;
            font-weight: 700;
            line-height: 1.05;
            color: #0F172A;
            text-align: right;
          }
          .metric-tile__secondary {
            width: 100%;
            margin-top: 3px;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            color: #475569;
            text-transform: uppercase;
            text-align: right;
          }
          .share-note {
            position: relative;
            z-index: 1;
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.72);
            border: 1px solid rgba(148,163,184,0.12);
            color: #334155;
            font-size: 14px;
            line-height: 1.5;
          }
          .share-attribution {
            position: relative;
            z-index: 1;
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid rgba(226,232,240,0.9);
            font-size: 10px;
            color: #A3B1C6;
            line-height: 1.25;
          }
          .minimal-hero { margin-bottom: 24px; }
          .distance-hero--minimal {
            gap: 8px;
            margin-bottom: 8px;
          }
          .minimal-distance {
            font-size: 70px;
            font-weight: 800;
            letter-spacing: -0.07em;
            font-style: normal;
            color: #0F172A;
          }
          .minimal-distance-unit {
            font-size: 24px;
            font-weight: 700;
            color: #F97316;
            padding-bottom: 10px;
          }
          .minimal-hero__subtitle {
            font-size: 15px;
            color: #475569;
          }
          .minimal-table {
            border-top: 1px solid #E2E8F0;
            border-bottom: 1px solid #E2E8F0;
            margin-bottom: 18px;
          }
          .minimal-row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 12px 0;
            border-bottom: 1px solid #F1F5F9;
            align-items: baseline;
          }
          .minimal-row:last-child { border-bottom: none; }
          .minimal-row__label {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #94A3B8;
          }
          .minimal-row__value {
            font-size: 16px;
            font-weight: 600;
            color: #0F172A;
            text-align: right;
          }
          .minimal-note {
            font-size: 15px;
            line-height: 1.55;
            color: #334155;
            margin-bottom: 16px;
          }
          .minimal-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #94A3B8;
            font-size: 12px;
          }
        </style>
      </head>
      <body>
        <div id="capture-shell">${bodyHtml}</div>
      </body>
    </html>
  `;
}

async function waitForPageAssets(page) {
  await page.evaluate(async () => {
    if (document.fonts?.ready) {
      await document.fonts.ready;
    }
    const pendingImages = Array.from(document.images).filter((img) => !img.complete);
    if (pendingImages.length === 0) {
      return;
    }
    await Promise.all(pendingImages.map((img) => new Promise((resolve) => {
      const finish = () => resolve();
      img.addEventListener('load', finish, { once: true });
      img.addEventListener('error', finish, { once: true });
    })));
  });
}

async function main() {
  const inputPath = getArg('--input');
  const outputPath = getArg('--output');
  if (!inputPath || !outputPath) {
    throw new Error('Usage: render_workout_share_card.mjs --input <payload.json> --output <card.png>');
  }

  const payload = JSON.parse(await fs.readFile(inputPath, 'utf8'));
  const html = await renderDocument(payload);
  const browser = await chromium.launch({ headless: true });

  try {
    const page = await browser.newPage({
      viewport: { width: 520, height: 1500 },
      deviceScaleFactor: 2,
    });

    await page.setContent(html, { waitUntil: 'load' });
    await waitForPageAssets(page);

    const capture = page.locator('#share-card-root');
    await capture.screenshot({
      path: outputPath,
      type: 'png',
      omitBackground: false,
    });
  } finally {
    await browser.close();
  }
}

main().catch((error) => {
  console.error(error?.stack || String(error));
  process.exit(1);
});
