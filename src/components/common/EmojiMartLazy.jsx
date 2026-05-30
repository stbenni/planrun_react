/**
 * Ленивый враппер emoji-mart с ЛОКАЛЬНЫМ Apple-набором через СПРАЙТШИТ.
 * Picker рендерит грид только в spritesheet-режиме (getImageURL он игнорирует),
 * поэтому даём ему:
 *  - data = apple.json (координаты x/y + sheet 61×61)
 *  - getSpritesheetURL → /emoji/apple-sheet-64.png (копия sheets-256/64.png v15.0.1)
 * ?v= — busting кэша, чтобы не подхватился старый битый спрайт.
 * Локаль ru локально. Всё в lazy-чанке, без CDN, офлайн-safe.
 */

import appleData from '@emoji-mart/data/sets/15/apple.json';
import ru from '@emoji-mart/data/i18n/ru.json';
import Picker from '@emoji-mart/react';

const SHEET_URL = '/emoji/apple-sheet-64.png?v=1501';

export default function EmojiMartLazy({ theme, onPick, dynamicWidth }) {
  return (
    <Picker
      data={appleData}
      i18n={ru}
      set="apple"
      spritesheet
      getSpritesheetURL={() => SHEET_URL}
      theme={theme}
      previewPosition="none"
      skinTonePosition="none"
      searchPosition="none"
      navPosition="bottom"
      dynamicWidth={dynamicWidth}
      maxFrequentRows={2}
      onEmojiSelect={(e) => onPick?.({ native: e.native, unified: e.unified })}
    />
  );
}
