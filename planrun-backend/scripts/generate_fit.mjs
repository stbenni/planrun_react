#!/usr/bin/env node
/**
 * generate_fit.mjs — собирает валидный FIT-Activity из данных тренировки PlanRun
 * (для заливки в Suunto через Workout Upload API). Использует официальный Garmin FIT SDK.
 *
 * Вход: --input <payload.json>, выход: --output <file.fit>
 * payload: { sport, subSport, startTimeMs, totalElapsedSec, totalDistanceM,
 *            avgHr, maxHr, totalCalories, totalAscent,
 *            points:[ {tMs, lat, lng, distM, hr, altM, speedMs, cad}, ... ] }
 */
import { Encoder, Profile } from '@garmin/fitsdk';
import fs from 'node:fs';

function arg(name) {
  const i = process.argv.indexOf(name);
  return i >= 0 ? process.argv[i + 1] : null;
}
const inPath = arg('--input');
const outPath = arg('--output');
if (!inPath || !outPath) {
  console.error('Usage: generate_fit.mjs --input <payload.json> --output <file.fit>');
  process.exit(2);
}

const p = JSON.parse(fs.readFileSync(inPath, 'utf8'));
const SEMI = 2147483648 / 180; // 2^31 / 180, градусы → semicircles
const toSemi = (deg) => Math.round(deg * SEMI);

const start = new Date(p.startTimeMs);
const end = new Date(p.startTimeMs + (p.totalElapsedSec || 0) * 1000);
const sport = p.sport || 'running';
const subSport = p.subSport || 'generic';

const enc = new Encoder();

enc.writeMesg({
  mesgNum: Profile.MesgNum.FILE_ID,
  type: 'activity',
  manufacturer: 'development',
  product: 0,
  serialNumber: 0,
  timeCreated: start,
});

enc.writeMesg({ mesgNum: Profile.MesgNum.EVENT, timestamp: start, event: 'timer', eventType: 'start' });

let firstLat = null, firstLng = null;
for (const pt of (p.points || [])) {
  const m = { mesgNum: Profile.MesgNum.RECORD, timestamp: new Date(pt.tMs) };
  if (pt.lat != null && pt.lng != null && (pt.lat !== 0 || pt.lng !== 0)) {
    m.positionLat = toSemi(pt.lat);
    m.positionLong = toSemi(pt.lng);
    if (firstLat === null) { firstLat = m.positionLat; firstLng = m.positionLong; }
  }
  if (pt.distM != null) m.distance = pt.distM;             // м
  if (pt.hr != null) m.heartRate = pt.hr;                  // bpm
  if (pt.altM != null) m.altitude = pt.altM;              // м
  if (pt.speedMs != null) m.speed = pt.speedMs;           // м/с
  if (pt.cad != null) m.cadence = Math.round(pt.cad / 2); // spm → rpm на ногу
  enc.writeMesg(m);
}

enc.writeMesg({ mesgNum: Profile.MesgNum.EVENT, timestamp: end, event: 'timer', eventType: 'stopAll' });

const lap = {
  mesgNum: Profile.MesgNum.LAP, timestamp: end, startTime: start,
  totalElapsedTime: p.totalElapsedSec, totalTimerTime: p.totalElapsedSec,
  totalDistance: p.totalDistanceM, sport, event: 'lap', eventType: 'stop',
};
if (firstLat !== null) { lap.startPositionLat = firstLat; lap.startPositionLong = firstLng; }
if (p.avgHr) lap.avgHeartRate = p.avgHr;
if (p.maxHr) lap.maxHeartRate = p.maxHr;
if (p.totalAscent) lap.totalAscent = p.totalAscent;
enc.writeMesg(lap);

const ses = {
  mesgNum: Profile.MesgNum.SESSION, timestamp: end, startTime: start,
  totalElapsedTime: p.totalElapsedSec, totalTimerTime: p.totalElapsedSec,
  totalDistance: p.totalDistanceM, sport, subSport,
  firstLapIndex: 0, numLaps: 1, event: 'session', eventType: 'stop',
};
if (firstLat !== null) { ses.startPositionLat = firstLat; ses.startPositionLong = firstLng; }
if (p.avgHr) ses.avgHeartRate = p.avgHr;
if (p.maxHr) ses.maxHeartRate = p.maxHr;
if (p.totalCalories) ses.totalCalories = p.totalCalories;
if (p.totalAscent) ses.totalAscent = p.totalAscent;
enc.writeMesg(ses);

enc.writeMesg({
  mesgNum: Profile.MesgNum.ACTIVITY, timestamp: end,
  totalTimerTime: p.totalElapsedSec, numSessions: 1, type: 'manual',
  event: 'activity', eventType: 'stop',
});

const bytes = enc.close();
fs.writeFileSync(outPath, Buffer.from(bytes));
console.log(JSON.stringify({ ok: true, bytes: bytes.length, records: (p.points || []).length, output: outPath }));
