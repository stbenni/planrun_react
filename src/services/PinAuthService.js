/**
 * Сервис для входа по PIN-коду (4 цифры).
 * Токены шифруются AES-GCM с ключом из PBKDF2.
 * Только для Capacitor (мобильное приложение).
 */

import { Preferences } from '@capacitor/preferences';
import { isNativeCapacitor } from './TokenStorageService';

const CURRENT_PBKDF2_ITERATIONS = 120000;
const LEGACY_PBKDF2_ITERATIONS = 1000;
const SALT_LENGTH = 16;
const IV_LENGTH = 12;
const KEY_LENGTH = 256;
const PREFIX = 'auth_pin_';
const LOCK_KEY = `${PREFIX}lock`;
const LOCK_THRESHOLD = 5;
const LOCK_BASE_SECONDS = 30;
const LOCK_MAX_SECONDS = 15 * 60;

async function getKeyMaterial(pin) {
  const encoder = new TextEncoder();
  return window.crypto.subtle.importKey(
    'raw',
    encoder.encode(pin),
    'PBKDF2',
    false,
    ['deriveBits', 'deriveKey']
  );
}

async function deriveKey(salt, pin) {
  return deriveKeyWithIterations(salt, pin, CURRENT_PBKDF2_ITERATIONS);
}

async function deriveKeyWithIterations(salt, pin, iterations) {
  const keyMaterial = await getKeyMaterial(pin);
  return window.crypto.subtle.deriveKey(
    {
      name: 'PBKDF2',
      salt,
      iterations,
      hash: 'SHA-256'
    },
    keyMaterial,
    { name: 'AES-GCM', length: KEY_LENGTH },
    false,
    ['encrypt', 'decrypt']
  );
}

function generateRandomBytes(length) {
  return window.crypto.getRandomValues(new Uint8Array(length));
}

function base64Encode(buffer) {
  return btoa(String.fromCharCode(...new Uint8Array(buffer)));
}

function base64Decode(str) {
  return Uint8Array.from(atob(str), (c) => c.charCodeAt(0));
}

function encodePayload(combined, iterations) {
  return `v2:${iterations}:${base64Encode(combined)}`;
}

function decodePayload(value) {
  if (typeof value !== 'string' || value.length === 0) {
    throw new Error('Данные не найдены');
  }
  if (value.startsWith('v2:')) {
    const match = value.match(/^v2:(\d+):(.+)$/);
    if (!match) throw new Error('Повреждённый формат данных');
    return {
      combined: base64Decode(match[2]),
      iterations: Number(match[1]) || CURRENT_PBKDF2_ITERATIONS
    };
  }
  return {
    combined: base64Decode(value),
    iterations: LEGACY_PBKDF2_ITERATIONS
  };
}

class PinAuthService {
  async isAvailable() {
    return typeof window !== 'undefined' && isNativeCapacitor() && !!window.crypto?.subtle;
  }

  async isPinEnabled() {
    try {
      if (!(await this.isAvailable())) return false;
      const r = await Preferences.get({ key: `${PREFIX}enabled` });
      return r.value === 'true';
    } catch {
      return false;
    }
  }

  async _getLockState() {
    try {
      const result = await Preferences.get({ key: LOCK_KEY });
      const parsed = result?.value ? JSON.parse(result.value) : null;
      if (!parsed || typeof parsed !== 'object') {
        return { failedAttempts: 0, lockedUntil: 0 };
      }
      return {
        failedAttempts: Number(parsed.failedAttempts) || 0,
        lockedUntil: Number(parsed.lockedUntil) || 0
      };
    } catch {
      return { failedAttempts: 0, lockedUntil: 0 };
    }
  }

  async _setLockState(state) {
    await Preferences.set({ key: LOCK_KEY, value: JSON.stringify(state) });
  }

  async _clearLockState() {
    await Preferences.remove({ key: LOCK_KEY });
  }

  async _checkLockState() {
    const state = await this._getLockState();
    const now = Date.now();
    if (state.lockedUntil > now) {
      const waitSeconds = Math.max(1, Math.ceil((state.lockedUntil - now) / 1000));
      return { locked: true, waitSeconds };
    }
    if (state.lockedUntil && state.lockedUntil <= now) {
      await this._clearLockState().catch(() => {});
    }
    return { locked: false, waitSeconds: 0 };
  }

  async _registerFailure() {
    const state = await this._getLockState();
    const failedAttempts = (state.failedAttempts || 0) + 1;
    let lockedUntil = 0;
    if (failedAttempts >= LOCK_THRESHOLD) {
      const lockSeconds = Math.min(
        LOCK_MAX_SECONDS,
        LOCK_BASE_SECONDS * (2 ** Math.max(0, failedAttempts - LOCK_THRESHOLD))
      );
      lockedUntil = Date.now() + lockSeconds * 1000;
    }
    await this._setLockState({ failedAttempts, lockedUntil });
    return { failedAttempts, lockedUntil };
  }

  /**
   * Установить PIN и сохранить токены в зашифрованном виде.
   * @param {string} pin - 4 цифры
   * @param {string} accessToken
   * @param {string} refreshToken
   */
  async setPinAndSaveTokens(pin, accessToken, refreshToken) {
    if (!accessToken || !refreshToken) {
      throw new Error('Токены обязательны');
    }
    const p = String(pin).replace(/\D/g, '');
    if (p.length !== 4) {
      throw new Error('PIN должен быть 4 цифры');
    }
    if (!(await this.isAvailable())) {
      throw new Error('PIN доступен только в мобильном приложении');
    }

    const salt = generateRandomBytes(SALT_LENGTH);
    const iv = generateRandomBytes(IV_LENGTH);
    const key = await deriveKey(salt, p);

    const encoder = new TextEncoder();
    const plaintext = encoder.encode(JSON.stringify({ accessToken, refreshToken }));

    const ciphertext = await window.crypto.subtle.encrypt(
      {
        name: 'AES-GCM',
        iv,
        tagLength: 128
      },
      key,
      plaintext
    );

    const combined = new Uint8Array(salt.length + iv.length + ciphertext.byteLength);
    combined.set(salt, 0);
    combined.set(iv, salt.length);
    combined.set(new Uint8Array(ciphertext), salt.length + iv.length);

    await Preferences.set({
      key: `${PREFIX}enabled`,
      value: 'true'
    });
    await Preferences.set({ key: `${PREFIX}data`, value: encodePayload(combined, CURRENT_PBKDF2_ITERATIONS) });
    await this._clearLockState().catch(() => {});

    return true;
  }

  /**
   * Проверить PIN и получить токены.
   * @param {string} pin
   * @returns {Promise<{success: boolean, tokens?: {accessToken: string, refreshToken: string}, error?: string}>}
   */
  async verifyAndGetTokens(pin) {
    try {
      if (!(await this.isAvailable())) {
        return { success: false, error: 'PIN доступен только в мобильном приложении' };
      }
      const lockState = await this._checkLockState();
      if (lockState.locked) {
        return {
          success: false,
          error: `Слишком много попыток. Попробуйте снова через ${lockState.waitSeconds} сек.`
        };
      }
      const enabled = await this.isPinEnabled();
      if (!enabled) {
        return { success: false, error: 'Вход по PIN не настроен' };
      }

      const dataResult = await Preferences.get({ key: `${PREFIX}data` });
      const dataB64 = dataResult?.value;
      if (!dataB64) {
        return { success: false, error: 'Данные не найдены. Войдите по паролю.' };
      }

      const { combined, iterations } = decodePayload(dataB64);
      const salt = combined.slice(0, SALT_LENGTH);
      const iv = combined.slice(SALT_LENGTH, SALT_LENGTH + IV_LENGTH);
      const ciphertext = combined.slice(SALT_LENGTH + IV_LENGTH);

      const p = String(pin).replace(/\D/g, '');
      const key = await deriveKeyWithIterations(salt, p, iterations);

      const decrypted = await window.crypto.subtle.decrypt(
        {
          name: 'AES-GCM',
          iv,
          tagLength: 128
        },
        key,
        ciphertext
      );

      const decoder = new TextDecoder();
      const json = decoder.decode(decrypted);
      const { accessToken, refreshToken } = JSON.parse(json);

      if (!accessToken || !refreshToken) {
        await this._registerFailure().catch(() => {});
        return { success: false, error: 'Неверный PIN' };
      }

      await this._clearLockState().catch(() => {});

      return {
        success: true,
        tokens: { accessToken, refreshToken }
      };
    } catch (e) {
      const isDecryptError = e?.message?.includes('operation') || e?.name === 'OperationError';
      if (isDecryptError) {
        const state = await this._registerFailure().catch(() => null);
        const lockedUntil = Number(state?.lockedUntil) || 0;
        if (lockedUntil > Date.now()) {
          const waitSeconds = Math.max(1, Math.ceil((lockedUntil - Date.now()) / 1000));
          return {
            success: false,
            error: `Слишком много попыток. Попробуйте снова через ${waitSeconds} сек.`
          };
        }
      }
      return {
        success: false,
        error: isDecryptError
          ? 'Неверный PIN или данные повреждены при обновлении. Войдите по паролю.'
          : (e?.message || 'Ошибка входа по PIN')
      };
    }
  }

  async clearPin() {
    try {
      if (!(await this.isAvailable())) return true;
      await Preferences.remove({ key: `${PREFIX}enabled` });
      await Preferences.remove({ key: `${PREFIX}data` });
      await this._clearLockState().catch(() => {});
      return true;
    } catch (e) {
      console.error('[PinAuth] clearPin failed:', e);
      return false;
    }
  }
}

export default new PinAuthService();
