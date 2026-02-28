/**
 * Резервное хранение логина и пароля для восстановления входа по PIN и биометрии.
 * Используется когда токены потеряны (KeyStore, обновление) или refresh истёк.
 * - SecureStorage: для восстановления по биометрии (читается после authenticate).
 * - Preferences (PIN-encrypted): для восстановления по PIN.
 * Только для Capacitor (native). Сохраняется автоматически при входе.
 */

import { Preferences } from '@capacitor/preferences';
import { isNativeCapacitor } from './TokenStorageService';

const PBKDF2_ITERATIONS = 1000;
const SALT_LENGTH = 16;
const IV_LENGTH = 12;
const KEY_LENGTH = 256;
const PREFIX = 'auth_cred_backup_';
const KEY_SECURE = 'auth_cred_backup_secure';

async function deriveKey(salt, pin) {
  const encoder = new TextEncoder();
  const keyMaterial = await window.crypto.subtle.importKey(
    'raw',
    encoder.encode(String(pin).replace(/\D/g, '')),
    'PBKDF2',
    false,
    ['deriveKey']
  );
  return window.crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt, iterations: PBKDF2_ITERATIONS, hash: 'SHA-256' },
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

class CredentialBackupService {
  async isAvailable() {
    return typeof window !== 'undefined' && isNativeCapacitor() && !!window.crypto?.subtle;
  }

  async hasCredentials() {
    try {
      if (!(await this.isAvailable())) return false;
      const r = await Preferences.get({ key: `${PREFIX}enabled` });
      if (r.value === 'true') return true;
      const storage = await this._getSecureStorage();
      if (storage) {
        const v = await storage.get(KEY_SECURE);
        return !!v;
      }
      return false;
    } catch {
      return false;
    }
  }

  async _getSecureStorage() {
    try {
      if (!isNativeCapacitor()) return null;
      const { SecureStorage } = await import('@aparajita/capacitor-secure-storage');
      await SecureStorage.setKeyPrefix('planrun_');
      return SecureStorage;
    } catch {
      return null;
    }
  }

  /**
   * Сохранить в SecureStorage (для восстановления по биометрии). Вызывается при каждом входе.
   */
  async saveCredentialsSecure(username, password) {
    if (!username || !password) return false;
    if (!(await this.isAvailable())) return false;
    const storage = await this._getSecureStorage();
    if (!storage) return false;
    try {
      await storage.set(KEY_SECURE, JSON.stringify({ username, password }));
      return true;
    } catch {
      return false;
    }
  }

  /**
   * Восстановить вход по биометрии: читает из SecureStorage, вызывает api.login.
   * Вызывать после BiometricAuth.authenticate().
   */
  async recoverAndLoginBiometric(api) {
    try {
      if (!(await this.isAvailable())) return { success: false, error: 'Недоступно' };
      if (!api) return { success: false, error: 'API не инициализирован' };
      const storage = await this._getSecureStorage();
      if (!storage) return { success: false, error: 'Хранилище недоступно' };
      const raw = await storage.get(KEY_SECURE);
      if (!raw) return { success: false, error: 'Нет сохранённых данных' };
      const { username, password } = JSON.parse(raw);
      if (!username || !password) return { success: false, error: 'Данные повреждены' };
      const result = await api.login(username, password, true);
      if (result?.success) return { success: true, user: result.user };
      return { success: false, error: result?.error || 'Ошибка входа' };
    } catch (e) {
      return { success: false, error: e?.message || 'Ошибка восстановления' };
    }
  }

  /**
   * Сохранить логин и пароль, зашифрованные PIN (для восстановления по PIN).
   * @param {string} pin - 4 цифры
   * @param {string} username
   * @param {string} password
   */
  async saveCredentials(pin, username, password) {
    if (!username || !password) return false;
    const p = String(pin).replace(/\D/g, '');
    if (p.length !== 4) return false;
    if (!(await this.isAvailable())) return false;

    const salt = generateRandomBytes(SALT_LENGTH);
    const iv = generateRandomBytes(IV_LENGTH);
    const key = await deriveKey(salt, p);

    const encoder = new TextEncoder();
    const plaintext = encoder.encode(JSON.stringify({ username, password }));

    const ciphertext = await window.crypto.subtle.encrypt(
      { name: 'AES-GCM', iv, tagLength: 128 },
      key,
      plaintext
    );

    const combined = new Uint8Array(salt.length + iv.length + ciphertext.byteLength);
    combined.set(salt, 0);
    combined.set(iv, salt.length);
    combined.set(new Uint8Array(ciphertext), salt.length + iv.length);

    await Preferences.set({ key: `${PREFIX}enabled`, value: 'true' });
    await Preferences.set({ key: `${PREFIX}data`, value: base64Encode(combined) });
    return true;
  }

  /**
   * Расшифровать и выполнить вход. При ошибке расшифровки возвращает { success: false }.
   * @param {string} pin
   * @param {object} api - ApiClient
   * @returns {Promise<{success: boolean, user?: object, error?: string}>}
   */
  async recoverAndLogin(pin, api) {
    try {
      if (!(await this.isAvailable())) return { success: false, error: 'Недоступно' };
      if (!(await this.hasCredentials())) return { success: false, error: 'Нет сохранённых данных' };
      if (!api) return { success: false, error: 'API не инициализирован' };

      const dataResult = await Preferences.get({ key: `${PREFIX}data` });
      const dataB64 = dataResult?.value;
      if (!dataB64) return { success: false, error: 'Данные не найдены' };

      const combined = base64Decode(dataB64);
      const salt = combined.slice(0, SALT_LENGTH);
      const iv = combined.slice(SALT_LENGTH, SALT_LENGTH + IV_LENGTH);
      const ciphertext = combined.slice(SALT_LENGTH + IV_LENGTH);

      const p = String(pin).replace(/\D/g, '');
      const key = await deriveKey(salt, p);

      const decrypted = await window.crypto.subtle.decrypt(
        { name: 'AES-GCM', iv, tagLength: 128 },
        key,
        ciphertext
      );

      const decoder = new TextDecoder();
      const { username, password } = JSON.parse(decoder.decode(decrypted));
      if (!username || !password) return { success: false, error: 'Неверный PIN' };

      const result = await api.login(username, password, true);
      if (result?.success) {
        return { success: true, user: result.user };
      }
      return { success: false, error: result?.error || 'Ошибка входа' };
    } catch (e) {
      const isDecryptError = e?.message?.includes('operation') || e?.name === 'OperationError';
      return {
        success: false,
        error: isDecryptError ? 'Неверный PIN' : (e?.message || 'Ошибка восстановления')
      };
    }
  }

  async clearCredentials() {
    try {
      if (!(await this.isAvailable())) return true;
      await Preferences.remove({ key: `${PREFIX}enabled` });
      await Preferences.remove({ key: `${PREFIX}data` });
      const storage = await this._getSecureStorage();
      if (storage) {
        try {
          await storage.remove(KEY_SECURE);
        } catch (_) {}
      }
      return true;
    } catch {
      return false;
    }
  }
}

export default new CredentialBackupService();
