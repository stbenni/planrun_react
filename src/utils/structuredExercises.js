/**
 * Парсинг плоского описания ОФП/СБУ в структурированный список упражнений.
 *
 * Принимает текст из двух источников:
 *  1) План (WorkoutBuilderService::buildOfpDescription):
 *     «Приседания со штангой — 4×12, 60 кг»
 *     «Планка — 3× по 60 сек»
 *  2) Notes из ResultModal.buildNotes():
 *     «ОФП: Приседания со штангой 3×10, 60 кг»
 *     «СБУ: Бег с захлёстом 4×30 м»
 *
 * Возвращает [{ name, sets, reps, weight, duration, distance, raw }, ...]
 * или null, если ни одной осмысленной строки распарсить не удалось.
 */
export function parseStructuredExercises(text) {
  if (!text || typeof text !== 'string') return null;

  const stripped = text
    .replace(/<\/p\s*>/gi, '\n')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<[^>]+>/g, '');

  const lines = stripped
    .split(/\r?\n/)
    .map((s) => s.trim())
    .filter(Boolean);
  if (lines.length === 0) return null;

  const result = [];
  for (const rawLine of lines) {
    const raw = rawLine.replace(/^\s*(ОФП|СБУ)\s*:\s*/i, '').trim();
    if (!raw) continue;

    let name = raw;
    let tail = '';

    const dashSplit = raw.split(/\s+—\s+|\s+-\s+/);
    if (dashSplit.length >= 2) {
      name = dashSplit[0].trim();
      tail = dashSplit.slice(1).join(' — ');
    } else {
      // Нет дефиса (формат из notes): найдём первое вхождение
      // числа с разделителем (×/x/*) или с единицей измерения.
      const cutMatch = raw.match(/\s+(?=\d+\s*(?:[×x*]|сек|м\b|кг))/i);
      if (cutMatch && cutMatch.index != null) {
        name = raw.slice(0, cutMatch.index).trim();
        tail = raw.slice(cutMatch.index).trim();
      }
    }

    const item = { name, raw };

    if (tail) {
      // Порядок важен: сначала пробуем «sets × X <единица>» (м/сек/мин/«по N мин/сек»),
      // и только если нет единицы — трактуем второе число как reps.
      // ВАЖНО: `\b` в JS не работает после кириллицы (codepoint > 127),
      // поэтому используем (?=$|[\s,;]) для проверки конца «слова».
      // Единицы времени: «сек», «мин», «ч». Дистанции: «м», «км».
      const setsDist = tail.match(/(\d+)\s*[×x*]\s*(\d+)\s*(км|м)(?=$|[\s,;])/i);
      if (setsDist) {
        item.sets = setsDist[1];
        item.distance = `${setsDist[2]} ${setsDist[3].toLowerCase()}`;
      }
      const setsDurationByPo = tail.match(/(\d+)\s*[×x*]\s*по\s*(\d+)\s*(сек|мин|ч)(?=$|[\s,;])/i);
      if (setsDurationByPo) {
        item.sets = setsDurationByPo[1];
        item.duration = `${setsDurationByPo[2]} ${setsDurationByPo[3].toLowerCase()}`;
      }
      const setsDurationShort = tail.match(/(\d+)\s*[×x*]\s*(\d+)\s*(сек|мин|ч)(?=$|[\s,;])/i);
      if (setsDurationShort && !item.duration) {
        item.sets = setsDurationShort[1];
        item.duration = `${setsDurationShort[2]} ${setsDurationShort[3].toLowerCase()}`;
      }
      // setsReps — только если выше не определили distance/duration.
      if (!item.distance && !item.duration) {
        const setsReps = tail.match(/(\d+)\s*[×x*]\s*(\d+)(?!\d)/i);
        if (setsReps) {
          item.sets = setsReps[1];
          item.reps = setsReps[2];
        }
      }
      const weight = tail.match(/(\d+(?:[.,]\d+)?)\s*кг/i);
      if (weight) item.weight = `${weight[1].replace(',', '.')} кг`;
      const standaloneDuration = tail.match(/(?<!по\s)(\d+)\s*(сек|мин|ч)(?=$|[\s,;])(?!\s*[×x*])/i);
      if (standaloneDuration && !item.duration) item.duration = `${standaloneDuration[1]} ${standaloneDuration[2].toLowerCase()}`;
    }

    result.push(item);
  }

  return result.length > 0 ? result : null;
}

/**
 * Группировка распарсенных упражнений на категории ОФП / СБУ.
 * Используется когда в notes смешаны префиксы «ОФП:» и «СБУ:».
 *
 * Возвращает { ofp: [...], sbu: [...], other: [...] }
 */
export function groupExercisesByCategory(text) {
  if (!text || typeof text !== 'string') return { ofp: [], sbu: [], other: [] };
  const lines = text.split(/\r?\n/).map((s) => s.trim()).filter(Boolean);
  const ofpLines = [];
  const sbuLines = [];
  const otherLines = [];
  for (const line of lines) {
    if (/^ОФП\s*:/i.test(line)) ofpLines.push(line);
    else if (/^СБУ\s*:/i.test(line)) sbuLines.push(line);
    else otherLines.push(line);
  }
  return {
    ofp: parseStructuredExercises(ofpLines.join('\n')) || [],
    sbu: parseStructuredExercises(sbuLines.join('\n')) || [],
    other: otherLines.join('\n').trim(),
  };
}
