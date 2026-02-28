/**
 * Сервис для входа по PIN-коду (4 цифры).
 * Токены шифруются AES-GCM с ключом из PBKDF2.
 * Только для Capacitor (мобильное приложение).
 */

import { Preferences } from '@capacitor/preferences';
import { isNativeCapacitor } from './TokenStorageService';

/** 1k итераций — быстрый отклик на мобильных; PIN 4 цифры даёт 10k вариантов, брутфорс всё равно ограничен */
const PBKDF2_ITERATIONS = 1000;
const SALT_LENGTH = 16;
const IV_LENGTH = 12;
const KEY_LENGTH = 256;
const PREFIX = 'auth_pin_';

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
  const keyMaterial = await getKeyMaterial(pin);
  return window.crypto.subtle.deriveKey(
    {
      name: 'PBKDF2',
      salt,
      iterations: PBKDF2_ITERATIONS,
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
    await Preferences.set({
      key: `${PREFIX}data`,
      value: base64Encode(combined)
    });

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
      const enabled = await this.isPinEnabled();
      if (!enabled) {
        return { success: false, error: 'Вход по PIN не настроен' };
      }

      const dataResult = await Preferences.get({ key: `${PREFIX}data` });
      const dataB64 = dataResult?.value;
      if (!dataB64) {
        return { success: false, error: 'Данные не найдены. Войдите по паролю.' };
      }

      const combined = base64Decode(dataB64);
      const salt = combined.slice(0, SALT_LENGTH);
      const iv = combined.slice(SALT_LENGTH, SALT_LENGTH + IV_LENGTH);
      const ciphertext = combined.slice(SALT_LENGTH + IV_LENGTH);

      const p = String(pin).replace(/\D/g, '');
      const key = await deriveKey(salt, p);

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
        return { success: false, error: 'Неверный PIN' };
      }

      return {
        success: true,
        tokens: { accessToken, refreshToken }
      };
    } catch (e) {
      const isDecryptError = e?.message?.includes('operation') || e?.name === 'OperationError';
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
      return true;
    } catch (e) {
      console.error('[PinAuth] clearPin failed:', e);
      return false;
    }
  }
}

export default new PinAuthService();
