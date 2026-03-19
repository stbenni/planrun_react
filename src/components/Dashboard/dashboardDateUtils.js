export function getDayItems(dayData) {
  if (!dayData) return [];
  const items = Array.isArray(dayData) ? dayData : [dayData];
  return items.filter((item) => item && item.type !== 'rest' && item.type !== 'free');
}

export function toLocalDateString(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

export function getTodayInTimezone(ianaTimezone) {
  try {
    const formatter = new Intl.DateTimeFormat('en-CA', {
      timeZone: ianaTimezone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit'
    });
    const parts = formatter.formatToParts(new Date());
    const year = parts.find((part) => part.type === 'year').value;
    const month = parts.find((part) => part.type === 'month').value;
    const day = parts.find((part) => part.type === 'day').value;
    return `${year}-${month}-${day}`;
  } catch {
    return toLocalDateString(new Date());
  }
}

export function addDaysToDateStr(dateStr, days) {
  const [year, month, day] = dateStr.split('-').map(Number);
  const date = new Date(Date.UTC(year, month - 1, day + days));
  return date.toISOString().split('T')[0];
}

export function dayItemsToWorkoutAndPlanDays(items, date, weekNumber, dayKey) {
  if (!items || items.length === 0) return null;
  const first = items[0];
  const workout = {
    type: first.type,
    text: first.text,
    date,
    weekNumber,
    dayKey,
  };
  const planDays = items.map((item) => ({
    id: item.id,
    type: item.type,
    description: item.text || '',
  }));
  return { workout, planDays };
}
