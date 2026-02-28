/**
 * Хранение токенов и device_id.
 * Web: localStorage.
 * Native: токены — SecureStorage (Keychain/Keystore); device_id — Preferences (SharedPreferences).
 * device_id в Preferences, т.к. SecureStorage на Android может зависать 3+ сек при первом обращении к KeyStore.
 *
 * Резервное хранение (backup): при потере KeyStore после обновления Android токены в SecureStorage
 * становятся нечитаемыми. Preferences-бэкап позволяет восстановить доступ без повторного входа.
 */

import { Capacitor } from '@capacitor/core';
import { Preferences } from '@capacitor/preferences';

const KEYS = {
  AUTH_TOKEN: 'auth_token',
  REFRESH_TOKEN: 'refresh_token',
  DEVICE_ID: 'planrun_device_id',
  /** Резервная копия токенов в Preferences (переживает потерю KeyStore при обновлении) */
  BACKUP_TOKENS: 'auth_tokens_backup'
};

/** SecureStorage на Android может зависать при обращении к KeyStore — ограничиваем ожидание */
const SECURE_STORAGE_TIMEOUT_MS = 5000;

function withTimeout(promise, ms = SECURE_STORAGE_TIMEOUT_MS) {
  let timeoutId;
  return Promise.race([
    promise,
    new Promise((_, reject) => {
      timeoutId = setTimeout(() => reject(new Error('SecureStorage timeout')), ms);
    })
  ]).finally(() => clearTimeout(timeoutId));
}

function generateUuid() {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) {
    return crypto.randomUUID();
  }
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

/** Только нативные платформы (Android/iOS). На web — localStorage. */
export function isNativeCapacitor() {
  try {
    return typeof Capacitor?.isNativePlatform === 'function'
      ? Capacitor.isNativePlatform()
      : ['android', 'ios'].includes(Capacitor?.getPlatform?.() || '');
  } catch {
    return false;
  }
}

class TokenStorageService {
  constructor() {
    this._storage = null;
  }

  async _getSecureStorage() {
    if (this._storage) return this._storage;
    if (!isNativeCapacitor()) return null;
    try {
      const { SecureStorage } = await import('@aparajita/capacitor-secure-storage');
      await SecureStorage.setKeyPrefix('planrun_');
      this._storage = SecureStorage;
      return this._storage;
    } catch (e) {
      if (process.env.NODE_ENV !== 'production') {
        console.warn('[TokenStorage] SecureStorage init failed:', e?.message);
      }
      return null;
    }
  }

  async getTokens() {
    if (typeof localStorage !== 'undefined' && !isNativeCapacitor()) {
      return {
        accessToken: localStorage.getItem(KEYS.AUTH_TOKEN),
        refreshToken: localStorage.getItem(KEYS.REFRESH_TOKEN)
      };
    }

    const storage = await this._getSecureStorage();
    if (!storage) {
      if (typeof localStorage !== 'undefined') {
        return {
          accessToken: localStorage.getItem(KEYS.AUTH_TOKEN),
          refreshToken: localStorage.getItem(KEYS.REFRESH_TOKEN)
        };
      }
      return { accessToken: null, refreshToken: null };
    }

    try {
      const [av, rv] = await withTimeout(Promise.all([
        storage.get(KEYS.AUTH_TOKEN),
        storage.get(KEYS.REFRESH_TOKEN)
      ]));
      const accessToken = (typeof av === 'string' ? av : null) || null;
      const refreshToken = (typeof rv === 'string' ? rv : null) || null;
      if (accessToken && refreshToken) {
        return { accessToken, refreshToken };
      }
    } catch (e) {
      if (process.env.NODE_ENV !== 'production') {
        console.warn('[TokenStorage] SecureStorage read failed (KeyStore lost?):', e?.message);
      }
      try {
        await withTimeout(Promise.all([
          storage.remove(KEYS.AUTH_TOKEN),
          storage.remove(KEYS.REFRESH_TOKEN)
        ]));
      } catch (_) {}
    }

    // Fallback: Preferences-бэкап (переживает потерю KeyStore при обновлении)
    try {
      const { value } = await Preferences.get({ key: KEYS.BACKUP_TOKENS });
      if (value && typeof value === 'string') {
        const parsed = JSON.parse(value);
        const at = parsed?.accessToken;
        const rt = parsed?.refreshToken;
        if (at && rt) {
          this._tryRestoreToSecureStorage(at, rt).catch(() => {});
          return { accessToken: at, refreshToken: rt };
        }
      }
    } catch (_) {
      // игнорируем
    }
    return { accessToken: null, refreshToken: null };
  }

  async _tryRestoreToSecureStorage(accessToken, refreshToken) {
    const storage = await this._getSecureStorage();
    if (!storage) return;
    try {
      await withTimeout(Promise.all([
        storage.set(KEYS.AUTH_TOKEN, String(accessToken)),
        storage.set(KEYS.REFRESH_TOKEN, String(refreshToken))
      ]));
    } catch (_) {}
  }

  async saveTokens(accessToken, refreshToken) {
    if (!accessToken || !refreshToken) return false;

    if (typeof localStorage !== 'undefined' && !isNativeCapacitor()) {
      localStorage.setItem(KEYS.AUTH_TOKEN, accessToken);
      localStorage.setItem(KEYS.REFRESH_TOKEN, refreshToken);
      return true;
    }

    const storage = await this._getSecureStorage();
    if (!storage) {
      if (typeof localStorage !== 'undefined') {
        localStorage.setItem(KEYS.AUTH_TOKEN, accessToken);
        localStorage.setItem(KEYS.REFRESH_TOKEN, refreshToken);
        return true;
      }
      return false;
    }

    try {
      await withTimeout(Promise.all([
        storage.set(KEYS.AUTH_TOKEN, String(accessToken)),
        storage.set(KEYS.REFRESH_TOKEN, String(refreshToken))
      ]));
    } catch (e) {
      if (process.env.NODE_ENV !== 'production') {
        console.warn('[TokenStorage] SecureStorage save failed:', e?.message);
      }
    }
    try {
      await Preferences.set({
        key: KEYS.BACKUP_TOKENS,
        value: JSON.stringify({ accessToken, refreshToken })
      });
    } catch (_) {}
    return true;
  }

  async clearTokens() {
    if (typeof localStorage !== 'undefined' && !isNativeCapacitor()) {
      localStorage.removeItem(KEYS.AUTH_TOKEN);
      localStorage.removeItem(KEYS.REFRESH_TOKEN);
      return true;
    }

    const storage = await this._getSecureStorage();
    if (!storage) {
      if (typeof localStorage !== 'undefined') {
        localStorage.removeItem(KEYS.AUTH_TOKEN);
        localStorage.removeItem(KEYS.REFRESH_TOKEN);
        return true;
      }
      return false;
    }

    try {
      await withTimeout(Promise.all([
        storage.remove(KEYS.AUTH_TOKEN),
        storage.remove(KEYS.REFRESH_TOKEN)
      ]));
    } catch (_) {}
    try {
      await Preferences.remove({ key: KEYS.BACKUP_TOKENS });
    } catch (_) {}
    return true;
  }

  async getDeviceId() {
    if (typeof localStorage !== 'undefined' && !isNativeCapacitor()) {
      return localStorage.getItem(KEYS.DEVICE_ID);
    }

    if (isNativeCapacitor()) {
      try {
        const { value } = await Preferences.get({ key: KEYS.DEVICE_ID });
        return typeof value === 'string' ? value : null;
      } catch (e) {
        return null;
      }
    }

    return typeof localStorage !== 'undefined' ? localStorage.getItem(KEYS.DEVICE_ID) : null;
  }

  async saveDeviceId(id) {
    if (!id) return false;

    if (typeof localStorage !== 'undefined' && !isNativeCapacitor()) {
      localStorage.setItem(KEYS.DEVICE_ID, id);
      return true;
    }

    if (isNativeCapacitor()) {
      try {
        await Preferences.set({ key: KEYS.DEVICE_ID, value: String(id) });
        return true;
      } catch (e) {
        return false;
      }
    }

    if (typeof localStorage !== 'undefined') {
      localStorage.setItem(KEYS.DEVICE_ID, id);
      return true;
    }
    return false;
  }

  async getOrCreateDeviceId() {
    let id = await this.getDeviceId();
    if (!id) {
      id = generateUuid();
      await this.saveDeviceId(id);
    }
    return id;
  }
}

export default new TokenStorageService();
