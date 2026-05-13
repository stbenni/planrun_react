/**
 * Маска для ввода темпа M:SS / MM:SS. Принимает любую строку, возвращает
 * корректно форматированную: только цифры, авто-вставка ":", clamp секунд ≤ 59.
 * Поддерживает диапазон 3:00–10:00 (1 цифра минут для 3-9, 2 цифры для 10).
 *
 * Используется в SettingsScreen и SpecializationModal/RegisterScreen — единый
 * паттерн ввода темпа для онбординга и редактирования настроек.
 */
export function formatPaceMask(raw) {
  const digits = String(raw || '').replace(/\D/g, '').slice(0, 4);
  if (!digits) return '';

  // Если первая цифра "1" — двузначные минуты (10:XX), иначе одиночные (3-9:XX)
  const twoDigitMin = digits[0] === '1';
  let formatted;
  if (twoDigitMin) {
    if (digits.length === 1) formatted = '1';
    else if (digits.length === 2) formatted = digits;
    else if (digits.length === 3) formatted = `${digits.slice(0, 2)}:${digits[2]}`;
    else formatted = `${digits.slice(0, 2)}:${digits.slice(2, 4)}`;
  } else {
    if (digits.length === 1) formatted = digits;
    else if (digits.length === 2) formatted = `${digits[0]}:${digits[1]}`;
    else formatted = `${digits[0]}:${digits.slice(1, 3)}`;
  }

  // Clamp секунд ≤ 59
  if (formatted.includes(':')) {
    const [m, s] = formatted.split(':');
    if (s.length === 2 && parseInt(s, 10) > 59) {
      formatted = `${m}:59`;
    }
  }

  return formatted;
}

export function paceMaskToSeconds(formatted) {
  if (!/^\d{1,2}:\d{2}$/.test(formatted)) return null;
  const [m, s] = formatted.split(':').map(Number);
  return m * 60 + s;
}
