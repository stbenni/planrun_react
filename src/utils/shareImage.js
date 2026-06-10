import { Capacitor } from '@capacitor/core';

export async function pickPhotoDataUrl(source = 'photos') {
  if (Capacitor.isNativePlatform()) {
    try {
      const { Camera, CameraSource, CameraResultType } = await import('@capacitor/camera');
      const src = source === 'camera' ? CameraSource.Camera
        : source === 'prompt' ? CameraSource.Prompt : CameraSource.Photos;
      const photo = await Camera.getPhoto({
        source: src,
        resultType: CameraResultType.DataUrl,
        quality: 90,
        width: 1600,
        correctOrientation: true,
      });
      return photo?.dataUrl || null;
    } catch (error) {
      if (process.env.NODE_ENV !== 'production') console.warn('Camera pick cancelled:', error?.message || error);
      return null;
    }
  }
  return new Promise((resolve) => {
    if (typeof document === 'undefined') { resolve(null); return; }
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.onchange = () => {
      const file = input.files && input.files[0];
      if (!file) { resolve(null); return; }
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result || '') || null);
      reader.onerror = () => resolve(null);
      reader.readAsDataURL(file);
    };
    input.click();
  });
}

export function loadImage(src) {
  return new Promise((resolve, reject) => {
    if (!src) { reject(new Error('Нет источника изображения')); return; }
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error('Не удалось загрузить изображение'));
    img.src = src;
  });
}

export function canvasToBlob(canvas, type = 'image/jpeg', quality = 0.92) {
  return new Promise((resolve, reject) => {
    if (!canvas || typeof canvas.toBlob !== 'function') {
      reject(new Error('Canvas недоступен'));
      return;
    }
    canvas.toBlob((blob) => {
      if (blob) resolve(blob);
      else reject(new Error('Пустой canvas'));
    }, type, quality);
  });
}

function blobToBase64(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onloadend = () => {
      const result = String(reader.result || '');
      const comma = result.indexOf(',');
      resolve(comma >= 0 ? result.slice(comma + 1) : result);
    };
    reader.onerror = () => reject(reader.error || new Error('Не удалось прочитать blob'));
    reader.readAsDataURL(blob);
  });
}

export function downloadBlob(blob, fileName) {
  if (!blob || typeof document === 'undefined' || typeof URL === 'undefined') return;
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = fileName;
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 0);
}

/**
 * Сохраняет blob в Галерею на нативе (MediaSaver), скачивает файлом в вебе.
 * В WebView <a download> не работает, поэтому на нативе идём через нативный плагин;
 * если он недоступен/отклонён — фолбэк на системный шит. Возвращает { saved, cancelled }.
 */
export async function saveImageBlob(blob, fileName) {
  if (!blob) return { saved: false, cancelled: false };
  if (Capacitor.isNativePlatform()) {
    try {
      const base64 = await blobToBase64(blob);
      const { default: MediaSaver } = await import('../plugins/mediaSaver');
      await MediaSaver.saveImage({ data: base64, fileName });
      return { saved: true, cancelled: false };
    } catch (error) {
      if (process.env.NODE_ENV !== 'production') console.warn('Native save failed, fallback to share:', error?.message || error);
      return shareImageBlob(blob, fileName, 'PlanRun');
    }
  }
  downloadBlob(blob, fileName);
  return { saved: true, cancelled: false };
}

async function shareNative(blob, fileName, title) {
  const { Filesystem, Directory } = await import('@capacitor/filesystem');
  const { Share } = await import('@capacitor/share');
  const base64 = await blobToBase64(blob);
  const written = await Filesystem.writeFile({
    path: fileName,
    data: base64,
    directory: Directory.Cache,
  });
  await Share.share({ title, files: [written.uri] });
  return { shared: true, cancelled: false };
}

async function shareWeb(blob, fileName, title) {
  if (typeof navigator !== 'undefined' && typeof navigator.share === 'function') {
    try {
      const file = new File([blob], fileName, { type: blob.type || 'image/jpeg' });
      if (typeof navigator.canShare !== 'function' || navigator.canShare({ files: [file] })) {
        await navigator.share({ title, files: [file] });
        return { shared: true, cancelled: false };
      }
    } catch (error) {
      if (error?.name === 'AbortError') return { shared: false, cancelled: true };
    }
  }
  downloadBlob(blob, fileName);
  return { shared: false, cancelled: false };
}

/**
 * Шарит готовый blob: нативный шит на мобилке, Web Share / скачивание в вебе.
 * Возвращает { shared, cancelled }.
 */
export async function shareImageBlob(blob, fileName, title = 'PlanRun') {
  if (!blob) return { shared: false, cancelled: false };
  try {
    if (Capacitor.isNativePlatform()) {
      return await shareNative(blob, fileName, title);
    }
  } catch (error) {
    if (error?.name === 'AbortError') return { shared: false, cancelled: true };
    if (process.env.NODE_ENV !== 'production') console.warn('Native share failed, fallback to web:', error?.message || error);
  }
  return shareWeb(blob, fileName, title);
}

export default shareImageBlob;
