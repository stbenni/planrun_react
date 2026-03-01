/**
 * Сервис для работы с биометрической аутентификацией
 * Использует @aparajita/capacitor-biometric-auth для Capacitor
 * API: checkBiometry(), authenticate(options), ошибки — BiometryError
 * Токены на Capacitor хранятся через TokenStorageService (SecureStorage).
 */

import { BiometricAuth } from '@aparajita/capacitor-biometric-auth';
import { Preferences } from '@capacitor/preferences';
import TokenStorageService, { isNativeCapacitor } from './TokenStorageService';

class BiometricService {
  constructor() {
    this.isAvailable = null;
    this.biometricType = null;
  }

  _isNative() {
    return typeof window !== 'undefined' && isNativeCapacitor();
  }

  /**
   * Проверить доступность биометрии на устройстве.
   * Использует checkBiometry() (v7+).
   */
  async checkAvailability() {
    try {
      if (!this._isNative()) {
        return { available: false, type: null, error: 'Только в мобильном приложении (Android/iOS)' };
      }

      const check = await BiometricAuth.checkBiometry();

      const available = check.isAvailable === true;
      const type = check.biometryType ?? null;
      const reason = check.reason ?? '';

      this.isAvailable = available;
      this.biometricType = type;

      if (process.env.NODE_ENV !== 'production') {
        console.log('[Biometric] check result:', { available, type, reason, code: check.code });
      }

      return {
        available,
        type,
        error: available ? null : (reason || (check.code ?? '')),
        code: check.code
      };
    } catch (error) {
      if (error?.message?.includes('not implemented on web')) {
        return { available: false, type: null, error: null };
      }
      console.error('[Biometric] check failed:', error);
      return {
        available: false,
        type: null,
        error: error?.message ?? String(error)
      };
    }
  }

  /**
   * Запросить биометрическую аутентификацию.
   * Опции в формате AuthenticateOptions (reason, cancelTitle, allowDeviceCredential, androidTitle и т.д.).
   * При ошибке плагин выбрасывает BiometryError (message, code).
   */
  async authenticate(reason = 'Подтвердите вашу личность') {
    try {
      if (!this._isNative()) {
        throw new Error('Биометрия доступна только в мобильном приложении');
      }

      const options = {
        reason,
        cancelTitle: 'Отмена',
        allowDeviceCredential: true,
        iosFallbackTitle: 'Использовать пароль',
        androidTitle: 'Биометрическая аутентификация',
        androidSubtitle: reason,
        androidConfirmationRequired: false,
        androidBiometryStrength: 'weak'
      };

      await BiometricAuth.authenticate(options);

      return { success: true, error: null };
    } catch (error) {
      const msg = error?.message ?? String(error);
      const code = error?.code ?? '';
      if (process.env.NODE_ENV !== 'production') {
        console.log('[Biometric] authenticate failed:', { code, message: msg });
      }
      return {
        success: false,
        error: msg || 'Биометрическая аутентификация не прошла',
        code
      };
    }
  }

  /**
   * Сохранить JWT токены в защищенном хранилище.
   * Сначала ставим флаг (Preferences — быстро), затем SecureStorage в фоне (может быть медленным).
   * Токены обычно уже в SecureStorage от логина; дублируем для надёжности.
   */
  async saveTokens(accessToken, refreshToken) {
    if (!accessToken || !refreshToken) {
      console.warn('[Biometric] saveTokens: оба токена обязательны');
      return false;
    }
    try {
      if (!this._isNative()) {
        if (typeof localStorage !== 'undefined') {
          localStorage.setItem('biometric_enabled', 'true');
          localStorage.setItem('auth_token', accessToken);
          localStorage.setItem('refresh_token', refreshToken);
          return true;
        }
        return false;
      }

      await Preferences.set({ key: 'biometric_enabled', value: 'true' });
      TokenStorageService.saveTokens(accessToken, refreshToken).catch((e) => {
        if (process.env.NODE_ENV !== 'production') {
          console.warn('[Biometric] SecureStorage save:', e?.message);
        }
      });
      return true;
    } catch (error) {
      console.error('Failed to save tokens:', error);
      return false;
    }
  }

  /**
   * Получить сохраненные токены.
   * Native: TokenStorageService (SecureStorage), при отсутствии — localStorage (fallback).
   */
  async getTokens() {
    try {
      if (!this._isNative()) {
        if (typeof localStorage !== 'undefined') {
          return {
            accessToken: localStorage.getItem('auth_token'),
            refreshToken: localStorage.getItem('refresh_token')
          };
        }
        return { accessToken: null, refreshToken: null };
      }

      if (typeof localStorage !== 'undefined') {
        const at = localStorage.getItem('auth_token');
        const rt = localStorage.getItem('refresh_token');
        if (at && rt) return { accessToken: at, refreshToken: rt };
      }
      const stored = await TokenStorageService.getTokens();
      if (stored?.accessToken && stored?.refreshToken) return stored;
      return { accessToken: null, refreshToken: null };
    } catch (error) {
      console.error('Failed to get tokens:', error);
      return { accessToken: null, refreshToken: null };
    }
  }

  /**
   * Проверить, включена ли биометрическая аутентификация
   * @returns {Promise<boolean>}
   */
  async isBiometricEnabled() {
    try {
      if (!this._isNative()) {
        if (typeof localStorage !== 'undefined') {
          return localStorage.getItem('biometric_enabled') === 'true';
        }
        return false;
      }

      const result = await Preferences.get({ key: 'biometric_enabled' });
      return result.value === 'true';
    } catch (error) {
      console.error('Failed to check biometric status:', error);
      return false;
    }
  }

  /**
   * Удалить сохраненные токены
   * @returns {Promise<boolean>}
   */
  async clearTokens() {
    try {
      if (!this._isNative()) {
        if (typeof localStorage !== 'undefined') {
          localStorage.removeItem('biometric_enabled');
          localStorage.removeItem('auth_token');
          localStorage.removeItem('refresh_token');
          return true;
        }
        return false;
      }

      await Preferences.remove({ key: 'biometric_enabled' });
      await TokenStorageService.clearTokens();
      return true;
    } catch (error) {
      console.error('Failed to clear tokens:', error);
      return false;
    }
  }

  /**
   * Полный цикл биометрической аутентификации.
   * Сначала authenticate (диалог «приложите палец»), затем getTokens.
   * Если токены не найдены (после обновления/потери KeyStore), возвращает success: true с tokens: null —
   * вызывающий код переходит к credential recovery.
   * Таймаут 30 сек — защита от зависания BiometricPrompt на Android.
   */
  async authenticateAndGetTokens(reason = 'Подтвердите вашу личность для входа') {
    const TIMEOUT_MS = 30000;
    const run = async () => {
      const isEnabled = await this.isBiometricEnabled();
      if (!isEnabled) {
        return { success: false, error: 'Биометрическая аутентификация не настроена' };
      }
      const authResult = await this.authenticate(reason);
      if (!authResult.success) {
        return { success: false, error: authResult.error || 'Биометрическая аутентификация не прошла' };
      }
      const tokens = await this.getTokens();
      const hasTokens = !!(tokens?.accessToken && tokens?.refreshToken);
      return { success: true, tokens: hasTokens ? tokens : null };
    };
    try {
      const timeoutPromise = new Promise((_, reject) =>
        setTimeout(() => reject(new Error('Таймаут. Попробуйте снова или войдите по паролю.')), TIMEOUT_MS)
      );
      return await Promise.race([run(), timeoutPromise]);
    } catch (error) {
      return {
        success: false,
        error: error?.message || 'Ошибка биометрической аутентификации'
      };
    }
  }
}

// Экспортируем singleton
export default new BiometricService();
