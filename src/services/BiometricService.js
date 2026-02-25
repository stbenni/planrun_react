/**
 * Сервис для работы с биометрической аутентификацией
 * Использует @aparajita/capacitor-biometric-auth для Capacitor
 * API: checkBiometry(), authenticate(options), ошибки — BiometryError
 */

import { BiometricAuth } from '@aparajita/capacitor-biometric-auth';
import { Preferences } from '@capacitor/preferences';

class BiometricService {
  constructor() {
    this.isAvailable = null;
    this.biometricType = null;
  }

  /**
   * Проверить доступность биометрии на устройстве.
   * Использует checkBiometry() (v7+), при отсутствии — checkAvailability() для обратной совместимости.
   */
  async checkAvailability() {
    try {
      if (typeof window === 'undefined' || !window.Capacitor) {
        return { available: false, type: null, error: 'Not in Capacitor environment' };
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
      if (typeof window === 'undefined' || !window.Capacitor) {
        throw new Error('Not in Capacitor environment');
      }

      const options = {
        reason,
        cancelTitle: 'Отмена',
        allowDeviceCredential: true,
        iosFallbackTitle: 'Использовать пароль',
        androidTitle: 'Биометрическая аутентификация',
        androidSubtitle: reason,
        androidConfirmationRequired: false
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
   * Оба токена обязательны; пустой refreshToken не сохраняем.
   */
  async saveTokens(accessToken, refreshToken) {
    if (!accessToken || !refreshToken) {
      console.warn('[Biometric] saveTokens: оба токена обязательны');
      return false;
    }
    try {
      if (typeof window === 'undefined' || !window.Capacitor) {
        // Для веба используем обычный localStorage
        if (typeof localStorage !== 'undefined') {
          localStorage.setItem('biometric_enabled', 'true');
          localStorage.setItem('auth_token', accessToken);
          localStorage.setItem('refresh_token', refreshToken);
          return true;
        }
        return false;
      }

      // Для мобильных приложений используем Preferences (защищенное хранилище)
      await Preferences.set({
        key: 'biometric_enabled',
        value: 'true'
      });

      await Preferences.set({
        key: 'auth_token',
        value: accessToken
      });

      await Preferences.set({
        key: 'refresh_token',
        value: refreshToken
      });

      return true;
    } catch (error) {
      console.error('Failed to save tokens:', error);
      return false;
    }
  }

  /**
   * Получить сохраненные токены из защищенного хранилища
   * @returns {Promise<{accessToken: string|null, refreshToken: string|null}>}
   */
  async getTokens() {
    try {
      if (typeof window === 'undefined' || !window.Capacitor) {
        // Для веба используем обычный localStorage
        if (typeof localStorage !== 'undefined') {
          return {
            accessToken: localStorage.getItem('auth_token'),
            refreshToken: localStorage.getItem('refresh_token')
          };
        }
        return { accessToken: null, refreshToken: null };
      }

      // Для мобильных приложений используем Preferences
      const accessToken = await Preferences.get({ key: 'auth_token' });
      const refreshToken = await Preferences.get({ key: 'refresh_token' });

      return {
        accessToken: accessToken.value || null,
        refreshToken: refreshToken.value || null
      };
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
      if (typeof window === 'undefined' || !window.Capacitor) {
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
      if (typeof window === 'undefined' || !window.Capacitor) {
        if (typeof localStorage !== 'undefined') {
          localStorage.removeItem('biometric_enabled');
          localStorage.removeItem('auth_token');
          localStorage.removeItem('refresh_token');
          return true;
        }
        return false;
      }

      await Preferences.remove({ key: 'biometric_enabled' });
      await Preferences.remove({ key: 'auth_token' });
      await Preferences.remove({ key: 'refresh_token' });

      return true;
    } catch (error) {
      console.error('Failed to clear tokens:', error);
      return false;
    }
  }

  /**
   * Полный цикл биометрической аутентификации
   * 1. Проверяет доступность биометрии
   * 2. Запрашивает биометрию
   * 3. Возвращает сохраненные токены
   * @param {string} reason - Причина запроса
   * @returns {Promise<{success: boolean, tokens?: {accessToken: string, refreshToken: string}, error?: string}>}
   */
  async authenticateAndGetTokens(reason = 'Подтвердите вашу личность для входа') {
    try {
      // Проверяем доступность
      const availability = await this.checkAvailability();
      if (!availability.available) {
        return {
          success: false,
          error: availability.error || 'Биометрия недоступна на этом устройстве'
        };
      }

      // Проверяем, включена ли биометрия
      const isEnabled = await this.isBiometricEnabled();
      if (!isEnabled) {
        return {
          success: false,
          error: 'Биометрическая аутентификация не настроена'
        };
      }

      // Запрашиваем биометрию
      const authResult = await this.authenticate(reason);
      if (!authResult.success) {
        return {
          success: false,
          error: authResult.error || 'Биометрическая аутентификация не прошла'
        };
      }

      // Получаем токены
      const tokens = await this.getTokens();
      if (!tokens.accessToken || !tokens.refreshToken) {
        return {
          success: false,
          error: 'Токены не найдены. Пожалуйста, войдите заново'
        };
      }

      return {
        success: true,
        tokens: {
          accessToken: tokens.accessToken,
          refreshToken: tokens.refreshToken
        }
      };
    } catch (error) {
      console.error('Biometric authentication flow failed:', error);
      return {
        success: false,
        error: error.message
      };
    }
  }
}

// Экспортируем singleton
export default new BiometricService();
