/**
 * Логгер: в браузере — только console; в Capacitor — console + запись в файл на устройстве.
 * Файл сохраняется между запусками (не теряется при вылете приложения).
 */

const LOG_FILE = 'planrun.log';
const MAX_LOG_BYTES = 200 * 1024; // ~200 KB, потом обрезка
const IS_CAPACITOR = typeof window !== 'undefined' && window.Capacitor?.isNativePlatform?.();

let Filesystem = null;
let Directory = null;
let Encoding = null;
let fsReady = null;

/** Вызвать при старте приложения (main.jsx), чтобы логи писались в файл на устройстве. */
export function initLogger() {
  if (!IS_CAPACITOR || fsReady) return fsReady;
  fsReady = import('@capacitor/filesystem').then((m) => {
    Filesystem = m.Filesystem;
    Directory = m.Directory;
    Encoding = m.Encoding;
  }).catch(() => {});
  return fsReady;
}

function timestamp() {
  return new Date().toISOString();
}

function format(level, args) {
  const msg = args.map((a) => (typeof a === 'object' ? JSON.stringify(a) : String(a))).join(' ');
  return `[${timestamp()}] [${level}] ${msg}\n`;
}

async function appendToFile(line) {
  if (!IS_CAPACITOR) return;
  if (fsReady) await fsReady;
  if (!Filesystem || !Directory || !Encoding) return;
  try {
    await Filesystem.appendFile({
      path: LOG_FILE,
      data: line,
      directory: Directory.Cache,
      encoding: Encoding.UTF8
    });
  } catch (e) {
    if (e.message?.includes('does not exist') || e.code?.includes('NOT_FOUND')) {
      try {
        await Filesystem.writeFile({
          path: LOG_FILE,
          data: line,
          directory: Directory.Cache,
          encoding: Encoding.UTF8,
          recursive: true
        });
      } catch (e2) {
        console.warn('Logger: writeFile failed', e2);
      }
    }
  }
}

async function trimLogIfNeeded() {
  if (!IS_CAPACITOR || !Filesystem || !Directory || !Encoding) return;
  try {
    const stat = await Filesystem.stat({ path: LOG_FILE, directory: Directory.Cache });
    if (stat.size <= MAX_LOG_BYTES) return;
    const { data } = await Filesystem.readFile({
      path: LOG_FILE,
      directory: Directory.Cache,
      encoding: Encoding.UTF8
    });
    const lines = String(data).split('\n');
    const keep = lines.slice(-Math.floor(MAX_LOG_BYTES / 80)); // ~80 bytes per line
    await Filesystem.writeFile({
      path: LOG_FILE,
      data: keep.join('\n') || '\n',
      directory: Directory.Cache,
      encoding: Encoding.UTF8
    });
  } catch (_) { /* ignore */ }
}

export const logger = {
  log(...args) {
    const line = format('INFO', args);
    console.log(...args);
    appendToFile(line).then(() => trimLogIfNeeded());
  },
  warn(...args) {
    const line = format('WARN', args);
    console.warn(...args);
    appendToFile(line).then(() => trimLogIfNeeded());
  },
  error(...args) {
    const line = format('ERROR', args);
    console.error(...args);
    appendToFile(line).then(() => trimLogIfNeeded());
  }
};

/**
 * Подключить глобальный перехват необработанных ошибок и промисов.
 * Вызывать один раз при старте приложения (например в main.jsx).
 */
export function installGlobalErrorLogger() {
  const prevOnError = window.onerror;
  window.onerror = function (message, source, lineno, colno, error) {
    logger.error('Uncaught', message, source, lineno, colno, error?.stack || error);
    if (typeof prevOnError === 'function') return prevOnError(message, source, lineno, colno, error);
    return false;
  };

  const prevUnhandled = window.onunhandledrejection;
  window.onunhandledrejection = function (event) {
    logger.error('Unhandled rejection', event.reason);
    if (typeof prevUnhandled === 'function') prevUnhandled.call(window, event);
  };
}

export default logger;
