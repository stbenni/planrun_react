/**
 * Маска ввода длительности (целевое/последнее время забега) в формате Ч:ММ:СС.
 *
 * Зачем не <input type="time">: на iOS Safari нативный пикер времени НЕ поддерживает
 * секунды и при step (в секундах) показывает минуты:секунды без часов — для забега
 * (10км/марафон) это ломает ввод. Плюс семантически результат забега — это длительность,
 * а не время суток. Поэтому используем текстовую маску, как для темпа (paceMask).
 *
 * Ввод СЛЕВА НАПРАВО: 1 → 1: → 1:3 → 1:35 → 1:35:0 → 1:35:00.
 * Первая цифра — часы (1 разряд, до 9ч хватает для бега), затем 2 цифры минут, 2 секунд.
 * Двоеточия вставляются автоматически, цифры не «прыгают» между позициями.
 *
 * Бэкенд (prompt_builder.php) парсит значение через explode(':') и принимает как
 * Ч:ММ:СС (3 части), так и ММ:СС (2 части) — маска совместима.
 */

/**
 * Форматирует ввод в Ч:ММ:СС слева направо. Берёт до 5 цифр (H MM SS),
 * авто-вставляет двоеточия по мере набора, clamp минут/секунд ≤ 59.
 * @param {string} raw
 * @returns {string}
 */
export function formatDurationMask(raw) {
  const digits = String(raw || '').replace(/\D/g, '').slice(0, 5);
  if (!digits) return '';

  const h = digits.slice(0, 1);
  let mm = digits.slice(1, 3);
  let ss = digits.slice(3, 5);

  // clamp по мере появления полных пар
  if (mm.length === 2 && parseInt(mm, 10) > 59) mm = '59';
  if (ss.length === 2 && parseInt(ss, 10) > 59) ss = '59';

  let out = h;
  if (digits.length >= 1 && digits.length < 2) {
    // только часы введены — показываем "1" (двоеточие добавится со следующей цифрой)
    return out;
  }
  // есть минуты (1-2 цифры)
  out = `${h}:${mm}`;
  if (digits.length <= 3) return out;
  // есть секунды (1-2 цифры)
  out = `${h}:${mm}:${ss}`;
  return out;
}

/**
 * Приводит частичный/готовый ввод к каноничному Ч:ММ:СС для отправки на бэк.
 * Возвращает '' если пусто. Недостающие минуты/секунды дополняет нулями.
 * @param {string} formatted
 * @returns {string}
 */
export function normalizeDuration(formatted) {
  const v = String(formatted || '').trim();
  if (!v) return '';
  const parts = v.split(':').map((p) => p.replace(/\D/g, ''));
  let h = 0;
  let m = 0;
  let s = 0;
  if (parts.length === 3) {
    [h, m, s] = parts.map((p) => parseInt(p || '0', 10));
  } else if (parts.length === 2) {
    [h, m] = parts.map((p) => parseInt(p || '0', 10));
  } else {
    h = parseInt(parts[0] || '0', 10);
  }
  if (m > 59) m = 59;
  if (s > 59) s = 59;
  const pad = (n) => String(n).padStart(2, '0');
  return `${h}:${pad(m)}:${pad(s)}`;
}

/** Секунды из строки Ч:ММ:СС / ММ:СС, либо null. */
export function durationToSeconds(formatted) {
  const v = String(formatted || '').trim();
  if (!v) return null;
  const parts = v.split(':').map((p) => parseInt(p.replace(/\D/g, '') || '0', 10));
  if (parts.some((n) => Number.isNaN(n))) return null;
  if (parts.length === 3) return parts[0] * 3600 + parts[1] * 60 + parts[2];
  if (parts.length === 2) return parts[0] * 3600 + parts[1] * 60;
  return parts[0] * 3600;
}
