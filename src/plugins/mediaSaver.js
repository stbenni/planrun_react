import { registerPlugin } from '@capacitor/core';

/**
 * Нативный плагин сохранения изображения в Галерею (Android). Реализация —
 * android/.../MediaSaverPlugin.kt (MediaStore на 10+, public Pictures на ≤ 9).
 * На web метод отсутствует — вызывать только под isNativePlatform() гвардом.
 *
 * MediaSaver.saveImage({ data, fileName }) -> { saved, uri }
 *   data — base64 (с data:-префиксом или без).
 */
const MediaSaver = registerPlugin('MediaSaver');

export default MediaSaver;
