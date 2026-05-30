/**
 * useVoiceRecorder — запись голосового через MediaRecorder.
 * start() запрашивает микрофон и начинает запись; stop() возвращает { file, duration, type };
 * cancel() прерывает без результата. seconds — текущая длительность (для таймера).
 */

import { useState, useRef, useCallback, useEffect } from 'react';

const MIME_CANDIDATES = [
  'audio/webm;codecs=opus',
  'audio/webm',
  'audio/mp4',
  'audio/ogg;codecs=opus',
  'audio/ogg',
];

function pickMime() {
  if (typeof MediaRecorder === 'undefined' || !MediaRecorder.isTypeSupported) return '';
  for (const c of MIME_CANDIDATES) {
    if (MediaRecorder.isTypeSupported(c)) return c;
  }
  return '';
}

function describeMicError(e) {
  const name = e?.name || '';
  switch (name) {
    case 'NotAllowedError':
    case 'PermissionDeniedError':
      return 'Доступ к микрофону запрещён. Разреши его в настройках браузера/приложения (значок 🔒 в адресной строке) и попробуй снова.';
    case 'NotFoundError':
    case 'DevicesNotFoundError':
      return 'Микрофон не найден на устройстве.';
    case 'NotReadableError':
    case 'TrackStartError':
      return 'Микрофон занят другим приложением. Закрой его и попробуй снова.';
    case 'SecurityError':
      return 'Доступ к микрофону заблокирован (небезопасный контекст). Нужен HTTPS.';
    case 'AbortError':
      return 'Запись прервана. Попробуй ещё раз.';
    default:
      return e?.message ? `Не удалось включить микрофон: ${e.message}` : 'Не удалось получить доступ к микрофону.';
  }
}

function extForType(type) {
  if (type.includes('mp4')) return 'm4a';
  if (type.includes('ogg')) return 'ogg';
  if (type.includes('mpeg')) return 'mp3';
  if (type.includes('wav')) return 'wav';
  return 'webm';
}

export function useVoiceRecorder() {
  const [recording, setRecording] = useState(false);
  const [seconds, setSeconds] = useState(0);
  const recorderRef = useRef(null);
  const streamRef = useRef(null);
  const chunksRef = useRef([]);
  const timerRef = useRef(null);
  const startedAtRef = useRef(0);
  const resolveRef = useRef(null);

  const cleanup = useCallback(() => {
    if (timerRef.current) { clearInterval(timerRef.current); timerRef.current = null; }
    if (streamRef.current) {
      streamRef.current.getTracks().forEach((t) => t.stop());
      streamRef.current = null;
    }
    recorderRef.current = null;
    chunksRef.current = [];
  }, []);

  useEffect(() => () => cleanup(), [cleanup]);

  const start = useCallback(async () => {
    if (recording) return;
    // getUserMedia доступен только в защищённом контексте (HTTPS или localhost).
    // На dev-сервере по http://<IP>:3200 микрофон будет заблокирован браузером.
    if (typeof window !== 'undefined' && window.isSecureContext === false) {
      throw new Error('Запись доступна только по защищённому соединению (HTTPS или localhost). Открой приложение по HTTPS.');
    }
    if (!navigator.mediaDevices?.getUserMedia) {
      throw new Error('Браузер не даёт доступ к микрофону здесь (нужен HTTPS или поддержка записи).');
    }
    if (typeof MediaRecorder === 'undefined') {
      throw new Error('Запись звука не поддерживается этим браузером.');
    }
    let stream;
    try {
      // Запрашиваем доступ к микрофону при каждом старте (браузер сам решит, показать ли запрос).
      stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch (e) {
      throw new Error(describeMicError(e));
    }
    streamRef.current = stream;
    const mimeType = pickMime();
    const recorder = mimeType ? new MediaRecorder(stream, { mimeType }) : new MediaRecorder(stream);
    recorderRef.current = recorder;
    chunksRef.current = [];

    recorder.ondataavailable = (e) => { if (e.data && e.data.size) chunksRef.current.push(e.data); };
    recorder.onstop = () => {
      const duration = Math.max(1, Math.round((Date.now() - startedAtRef.current) / 1000));
      const baseType = (recorder.mimeType || mimeType || 'audio/webm').split(';')[0];
      const blob = new Blob(chunksRef.current, { type: recorder.mimeType || mimeType || 'audio/webm' });
      const file = new File([blob], `voice.${extForType(baseType)}`, { type: baseType });
      cleanup();
      setRecording(false);
      setSeconds(0);
      const resolve = resolveRef.current;
      resolveRef.current = null;
      resolve?.(blob.size > 0 ? { file, duration, type: baseType } : null);
    };

    startedAtRef.current = Date.now();
    recorder.start();
    setRecording(true);
    setSeconds(0);
    timerRef.current = setInterval(() => setSeconds((s) => s + 1), 1000);
  }, [recording, cleanup]);

  const stop = useCallback(() => new Promise((resolve) => {
    const recorder = recorderRef.current;
    if (!recorder || recorder.state === 'inactive') { resolve(null); return; }
    resolveRef.current = resolve;
    recorder.stop();
  }), []);

  const cancel = useCallback(() => {
    const recorder = recorderRef.current;
    resolveRef.current = null;
    if (recorder && recorder.state !== 'inactive') {
      recorder.onstop = null;
      try { recorder.stop(); } catch { /* noop */ }
    }
    cleanup();
    setRecording(false);
    setSeconds(0);
  }, [cleanup]);

  return { recording, seconds, start, stop, cancel };
}
