/**
 * Базовый путь к локальным Apple-эмодзи (бандлятся в public/emoji/apple).
 * Вынесено отдельно, чтобы и пикер, и рендер сообщений ссылались на один источник
 * без подтягивания тяжёлого emoji-mart Picker.
 */

export const APPLE_EMOJI_BASE = '/emoji/apple';

export const appleEmojiImageURL = (unified) => `${APPLE_EMOJI_BASE}/${unified}.png`;
