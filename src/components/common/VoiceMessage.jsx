/**
 * VoiceMessage — плеер голосового сообщения: play/pause + прогресс + длительность.
 * src — URL аудио; duration — длительность в секундах (из metadata).
 */

import { useRef, useState, useCallback } from 'react';
import { PlayIcon, PauseIcon } from './Icons';
import './VoiceMessage.css';

function fmtTime(s) {
  const total = Math.max(0, Math.round(s || 0));
  const m = Math.floor(total / 60);
  const sec = total % 60;
  return `${m}:${String(sec).padStart(2, '0')}`;
}

export default function VoiceMessage({ src, duration = 0 }) {
  const audioRef = useRef(null);
  const [playing, setPlaying] = useState(false);
  const [progress, setProgress] = useState(0);
  const [current, setCurrent] = useState(0);

  const toggle = useCallback(() => {
    const a = audioRef.current;
    if (!a) return;
    if (a.paused) a.play().catch(() => {});
    else a.pause();
  }, []);

  const onTime = useCallback((e) => {
    const a = e.currentTarget;
    const dur = Number.isFinite(a.duration) && a.duration > 0 ? a.duration : duration;
    setCurrent(a.currentTime);
    setProgress(dur ? Math.min(1, a.currentTime / dur) : 0);
  }, [duration]);

  const seek = useCallback((e) => {
    const a = audioRef.current;
    if (!a) return;
    const rect = e.currentTarget.getBoundingClientRect();
    const ratio = Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width));
    const dur = Number.isFinite(a.duration) && a.duration > 0 ? a.duration : duration;
    if (dur) { a.currentTime = ratio * dur; setProgress(ratio); }
  }, [duration]);

  return (
    <div className="voice-msg">
      <button type="button" className="voice-msg__btn" onClick={toggle} aria-label={playing ? 'Пауза' : 'Воспроизвести'}>
        {playing ? <PauseIcon size={18} /> : <PlayIcon size={18} />}
      </button>
      <div className="voice-msg__track" onClick={seek}>
        <div className="voice-msg__fill" style={{ width: `${progress * 100}%` }} />
      </div>
      <span className="voice-msg__time">{fmtTime(playing || current ? current : duration)}</span>
      <audio
        ref={audioRef}
        src={src}
        preload="metadata"
        onPlay={() => setPlaying(true)}
        onPause={() => setPlaying(false)}
        onEnded={() => { setPlaying(false); setProgress(0); setCurrent(0); }}
        onTimeUpdate={onTime}
      />
    </div>
  );
}
