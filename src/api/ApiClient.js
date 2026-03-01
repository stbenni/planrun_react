/**
 * Универсальный API клиент для React (веб) и React Native (мобильное)
 * Один код для всех платформ
 */

import BiometricService from '../services/BiometricService';
import PinAuthService from '../services/PinAuthService';
import TokenStorageService, { isNativeCapacitor } from '../services/TokenStorageService';

class ApiError extends Error {
  constructor({ code, message, attempts_left }) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.message = message;
    this.attempts_left = attempts_left;
  }
}

const PROACTIVE_REFRESH_MS = 60000; // обновлять за 60 сек до истечения
const REQUEST_TIMEOUT_MS = 15000; // таймаут обычных запросов (чтобы не зависать при недоступном API)
const INITIAL_AUTH_TIMEOUT_MS = 5000; // короткий таймаут для check_auth при первом запуске (нет ключей → сразу логин)
const SYNC_WORKOUTS_TIMEOUT_MS = 120000; // 2 мин — Strava/Huawei синхронизация (много API-запросов, прокси)

function getExpFromToken(token) {
  try {
    const parts = String(token).split('.');
    if (parts.length !== 3) return null;
    const payload = parts[1].replace(/-/g, '+').replace(/_/g, '/');
    const decoded = JSON.parse(atob(payload));
    return typeof decoded.exp === 'number' ? decoded.exp : null;
  } catch {
    return null;
  }
}

class ApiClient {
  constructor(baseUrl = null) {
    const origin = typeof window !== 'undefined' ? window.location.origin : '';
    const envBase = typeof import.meta !== 'undefined' && import.meta.env && import.meta.env.VITE_API_BASE_URL;
    const explicit = baseUrl ?? (envBase !== undefined && envBase !== '' ? envBase : null);

    if (explicit) {
      this.baseUrl = explicit;
    } else {
      const isNativeOrigin = /^(capacitor|file):\/\//.test(origin || '');
      if (isNativeOrigin && envBase) {
        this.baseUrl = envBase.endsWith('/api') ? envBase : `${envBase.replace(/\/$/, '')}/api`;
      } else if (isNativeOrigin) {
        this.baseUrl = 'https://s-vladimirov.ru/api';
        if (process.env.NODE_ENV !== 'production') {
          console.warn('[ApiClient] Native origin without VITE_API_BASE_URL — используем fallback. Для продакшена задайте VITE_API_BASE_URL при сборке.');
        }
      } else if (typeof window !== 'undefined') {
        this.baseUrl = '/api';
      } else {
        this.baseUrl = origin ? origin + '/api' : '/api';
      }
    }
    this.token = null;
    this.refreshToken = null;
    this.onTokenExpired = null;
    this.isRefreshing = false;
    this.refreshPromise = null;
  }

  async getOrCreateDeviceId() {
    return TokenStorageService.getOrCreateDeviceId();
  }

  async setToken(token, refreshToken = null) {
    this.token = token;
    if (token && refreshToken) {
      this.refreshToken = refreshToken;
    } else if (!token) {
      this.refreshToken = null;
    }

    if (isNativeCapacitor()) {
      if (token && refreshToken) {
        if (typeof localStorage !== 'undefined') {
          localStorage.setItem('auth_token', token);
          localStorage.setItem('refresh_token', refreshToken);
        }
        // Не блокируем: localStorage уже записан, SecureStorage/Preferences сохраняются в фоне.
        // TokenStorageService.saveTokens сначала пишет Preferences (быстро), потом SecureStorage (может зависнуть).
        TokenStorageService.saveTokens(token, refreshToken).catch((e) => {
          if (process.env.NODE_ENV !== 'production') {
            console.warn('[ApiClient] SecureStorage save:', e?.message);
          }
        });
      } else {
        if (typeof localStorage !== 'undefined') {
          localStorage.removeItem('auth_token');
          localStorage.removeItem('refresh_token');
        }
        TokenStorageService.clearTokens().catch(() => {});
      }
      return;
    }

    if (typeof localStorage !== 'undefined') {
      if (token) {
        localStorage.setItem('auth_token', token);
        if (refreshToken) {
          localStorage.setItem('refresh_token', refreshToken);
        }
      } else {
        localStorage.removeItem('auth_token');
        localStorage.removeItem('refresh_token');
      }
    } else {
      // Для React Native используем AsyncStorage (асинхронно)
      // AsyncStorage должен быть импортирован в React Native версии
      try {
        // В React Native версии нужно добавить: import AsyncStorage from '@react-native-async-storage/async-storage';
        // Здесь используем глобальный AsyncStorage если доступен
        if (typeof AsyncStorage !== 'undefined') {
          if (token) {
            await AsyncStorage.setItem('auth_token', token);
            if (refreshToken) {
              await AsyncStorage.setItem('refresh_token', refreshToken);
            }
          } else {
            await AsyncStorage.removeItem('auth_token');
            await AsyncStorage.removeItem('refresh_token');
          }
        }
      } catch (error) {
        console.error('Error setting token:', error);
      }
    }
  }

  /** Текущие токены (для синхронизации в PinAuthService после успешного unlock) */
  getTokens() {
    return { accessToken: this.token, refreshToken: this.refreshToken };
  }

  async getToken() {
    let token = this.token;
    if (isNativeCapacitor()) {
      if (token) return token;
      if (typeof localStorage !== 'undefined') {
        const stored = localStorage.getItem('auth_token');
        const refresh = localStorage.getItem('refresh_token');
        if (stored && refresh) {
          this.token = stored;
          this.refreshToken = refresh;
          return stored;
        }
      }
      try {
        const stored = await TokenStorageService.getTokens();
        if (stored?.accessToken && stored?.refreshToken) {
          this.token = stored.accessToken;
          this.refreshToken = stored.refreshToken;
          token = stored.accessToken;
        }
      } catch (e) {
        // игнорируем
      }
    } else if (!token && typeof localStorage !== 'undefined') {
      token = localStorage.getItem('auth_token');
      const refreshToken = localStorage.getItem('refresh_token');
      if (token) {
        this.token = token;
        if (refreshToken) this.refreshToken = refreshToken;
      }
    }
    if (!token && typeof window !== 'undefined' && window.Capacitor && !isNativeCapacitor()) {
      try {
        const stored = await BiometricService.getTokens();
        if (stored?.accessToken && stored?.refreshToken) {
          await this.setToken(stored.accessToken, stored.refreshToken);
          token = stored.accessToken;
        }
      } catch (e) {
        // игнорируем
      }
    }
    if (!token && typeof AsyncStorage !== 'undefined') {
      try {
        token = await AsyncStorage.getItem('auth_token');
        const refreshToken = await AsyncStorage.getItem('refresh_token');
        if (token) {
          this.token = token;
          if (refreshToken) this.refreshToken = refreshToken;
        }
      } catch (error) {
        console.error('Error getting token:', error);
      }
    }
    if (!token) return null;

    const exp = getExpFromToken(token);
    if (exp && exp * 1000 - PROACTIVE_REFRESH_MS < Date.now()) {
      try {
        const newToken = await this.refreshAccessToken();
        return newToken;
      } catch (e) {
        return token;
      }
    }
    return token;
  }

  async getRefreshToken() {
    if (this.refreshToken) {
      return this.refreshToken;
    }

    if (isNativeCapacitor()) {
      if (typeof localStorage !== 'undefined') {
        const stored = localStorage.getItem('refresh_token');
        if (stored) {
          this.refreshToken = stored;
          return stored;
        }
      }
      try {
        const stored = await TokenStorageService.getTokens();
        if (stored?.accessToken && stored?.refreshToken) {
          this.token = stored.accessToken;
          this.refreshToken = stored.refreshToken;
          return stored.refreshToken;
        }
      } catch (e) {
        // игнорируем
      }
      return null;
    }

    if (typeof localStorage !== 'undefined') {
      const stored = localStorage.getItem('refresh_token');
      if (stored) {
        this.refreshToken = stored;
        return stored;
      }
      if (typeof window !== 'undefined' && window.Capacitor) {
        try {
          const tokens = await BiometricService.getTokens();
          if (tokens?.accessToken && tokens?.refreshToken) {
            await this.setToken(tokens.accessToken, tokens.refreshToken);
            return tokens.refreshToken;
          }
        } catch (e) {
          // игнорируем
        }
      }
      return null;
    }

    try {
      if (typeof AsyncStorage !== 'undefined') {
        return await AsyncStorage.getItem('refresh_token');
      }
    } catch (error) {
      console.error('Error getting refresh token:', error);
      return null;
    }
    return null;
  }

  /**
   * Обновить access token используя refresh token
   */
  async refreshAccessToken() {
    // Если уже обновляем, возвращаем существующий промис
    if (this.isRefreshing && this.refreshPromise) {
      return this.refreshPromise;
    }

    this.isRefreshing = true;
    this._tokenExpiredFiredInRefresh = false;
    let tokenExpiredHandled = false;
    this.refreshPromise = (async () => {
      try {
        const refreshToken = await this.getRefreshToken();
        if (!refreshToken) {
          throw new Error('No refresh token available');
        }

        // Если refresh-токен уже истёк — не делаем запрос, сразу переходим на экран входа
        const refreshExp = getExpFromToken(refreshToken);
        if (refreshExp && refreshExp * 1000 < Date.now()) {
          await this.setToken(null, null);
          if (this.onTokenExpired) {
            this._tokenExpiredFiredInRefresh = true;
            this.onTokenExpired();
            tokenExpiredHandled = true;
          }
          throw new Error('Refresh token expired');
        }

        const urlParams = new URLSearchParams({ action: 'refresh_token' });
        const url = `${this.baseUrl}/api_wrapper.php?${urlParams.toString()}`;

        const deviceId = await this.getOrCreateDeviceId();
        const body = { refresh_token: refreshToken };
        if (deviceId) body.device_id = deviceId;

        let response;
        try {
          response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
          });
        } catch (fetchError) {
          fetchError.isNetworkError = true;
          if (process.env.NODE_ENV !== 'production') {
            console.warn('[ApiClient] Refresh network error:', fetchError?.message);
          }
          throw fetchError;
        }

        const data = await response.json().catch(() => ({}));
        if (response.ok && data.success && data.data) {
          const { access_token, refresh_token } = data.data;
          await this.setToken(access_token, refresh_token);
          // Синхронизация в BiometricService — иначе после refresh PIN/биометрия будут использовать устаревшие токены
          if (isNativeCapacitor()) {
            try {
              const [pinEnabled, biometricEnabled] = await Promise.all([
                PinAuthService.isPinEnabled(),
                BiometricService.isBiometricEnabled()
              ]);
              if (biometricEnabled) {
                BiometricService.saveTokens(access_token, refresh_token).catch(() => {});
              }
              // PinAuthService требует PIN для шифрования — обновляется при успешном unlock в useAuthStore
            } catch (e) {
              if (process.env.NODE_ENV !== 'production') {
                console.warn('[ApiClient] Sync tokens after refresh:', e?.message);
              }
            }
          }
          return access_token;
        }

        // Сервер явно отклонил refresh (401, истёк, отозван)
        const errMsg = (data.error || data.message || '').toLowerCase();
        const isTokenInvalid = response.status === 401 || /invalid|expired|revoked|unauthorized/.test(errMsg);
        if (isTokenInvalid) {
          await this.setToken(null, null);
          if (this.onTokenExpired) {
            this._tokenExpiredFiredInRefresh = true;
            this.onTokenExpired();
            tokenExpiredHandled = true;
          }
        }
        throw new Error(data.error || data.message || 'Failed to refresh token');
      } catch (error) {
        const isNetworkError = error?.isNetworkError ||
          error?.name === 'TypeError' ||
          error?.name === 'AbortError' ||
          /fetch|network|timeout|abort|ECONNREFUSED|ENOTFOUND|ERR_NAME|Failed to fetch|Load failed|NetworkError|SecureStorage/i.test(error?.message || '');
        if (!isNetworkError && !tokenExpiredHandled && this.onTokenExpired) {
          this._tokenExpiredFiredInRefresh = true;
          this.onTokenExpired();
        }
        throw error;
      } finally {
        this.isRefreshing = false;
        this.refreshPromise = null;
      }
    })();

    return this.refreshPromise;
  }

  /**
   * Базовый метод для выполнения запросов к api.php
   */
  async request(action, params = {}, method = 'GET') {
    const token = await this.getToken();
    
    // Формируем URL - action всегда в URL
    const urlParams = new URLSearchParams({ action });
    
    // Для check_plan_status ошибка может быть частью ответа (success: true с error)
    const allowErrorInResponse = action === 'check_plan_status';
    
    // Используем api_wrapper.php который проксирует к api_v2.php
    // api_wrapper.php уже настроен с CORS и работает правильно
    const url = `${this.baseUrl}/api_wrapper.php?${urlParams.toString()}`;

    const headers = {
      'Content-Type': 'application/json',
    };

    const options = {
      method,
      headers,
    };
    // Чат — не кэшировать (сообщения обновляются)
    if (action === 'chat_get_messages' || action === 'chat_get_direct_messages') {
      options.cache = 'no-store';
    }

    // Настройки для разных платформ
    if (typeof window !== 'undefined' && window.Capacitor) {
      // Capacitor - НЕ используем credentials (cookies не работают надежно)
      // Вместо этого передаем токен в заголовках
    } else {
      // Веб - CORS режим с cookies
      options.credentials = 'include';
      options.mode = 'cors';
    }

    // Добавляем токен авторизации если есть (token уже получен выше)
    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    // Для POST запросов отправляем JSON в теле запроса
    if (method === 'POST' && Object.keys(params).length > 0) {
      // Отправляем как JSON, так как updateProfile использует getJsonBody()
      options.body = JSON.stringify(params);
      headers['Content-Type'] = 'application/json';
    } else if (method === 'GET' && Object.keys(params).length > 0) {
      // Для GET запросов добавляем параметры в URL
      Object.keys(params).forEach(key => {
        if (params[key] !== undefined && params[key] !== null) {
          if (typeof params[key] === 'object') {
            urlParams.append(key, JSON.stringify(params[key]));
          } else {
            urlParams.append(key, String(params[key]));
          }
        }
      });
      const urlWithParams = `${this.baseUrl}/api_wrapper.php?${urlParams.toString()}`;
      // Обновляем URL с параметрами
      options.url = urlWithParams;
    }

    try {
      // Используем URL с параметрами для GET или базовый URL для POST
      const finalUrl = (method === 'GET' && options.url) ? options.url : url;
      const controller = new AbortController();
      let timeoutMs = REQUEST_TIMEOUT_MS;
      if (action === 'check_auth') timeoutMs = INITIAL_AUTH_TIMEOUT_MS;
      else if (action === 'sync_workouts') timeoutMs = SYNC_WORKOUTS_TIMEOUT_MS;
      const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
      const fetchOptions = { ...options, signal: controller.signal };
      let response;
      try {
        response = await fetch(finalUrl, fetchOptions);
      } finally {
        clearTimeout(timeoutId);
      }
      
      // Обработка ошибок авторизации
      if (response.status === 401) {
        const refreshToken = await this.getRefreshToken();
        // Если уже идёт обновление — ждём его и повторяем запрос с новым токеном
        if (this.isRefreshing && this.refreshPromise) {
          try {
            const newToken = await this.refreshPromise;
            headers['Authorization'] = `Bearer ${newToken}`;
            const retryResponse = await fetch(finalUrl, { ...options, headers });
            if (retryResponse.ok) {
              const retryData = await retryResponse.json();
              return retryData.data || retryData;
            }
          } catch (refreshErr) {
            if (refreshErr?.isNetworkError) {
              throw new ApiError({ code: 'NETWORK_ERROR', message: 'Нет соединения. Проверьте интернет и попробуйте снова.' });
            }
          }
        } else if (refreshToken) {
          try {
            const newToken = await this.refreshAccessToken();
            headers['Authorization'] = `Bearer ${newToken}`;
            const retryResponse = await fetch(finalUrl, { ...options, headers });
            if (retryResponse.ok) {
              const retryData = await retryResponse.json();
              return retryData.data || retryData;
            }
          } catch (refreshError) {
            if (refreshError?.isNetworkError) {
              throw new ApiError({ code: 'NETWORK_ERROR', message: 'Нет соединения. Проверьте интернет и попробуйте снова.' });
            }
            if (!refreshError?.isNetworkError) {
              console.error('Failed to refresh token:', refreshError);
            }
          }
        }
        
        if (this.onTokenExpired && !this._tokenExpiredFiredInRefresh) {
          this.onTokenExpired();
        }
        throw new ApiError({ code: 'UNAUTHORIZED', message: 'Требуется авторизация' });
      }
      
      if (response.status === 403) {
        // Пытаемся получить сообщение об ошибке из ответа
        let errorMessage = 'Доступ запрещен';
        try {
          const errorData = await response.json();
          errorMessage = errorData.error || errorData.message || errorMessage;
        } catch (e) {
          // Игнорируем ошибку парсинга
        }
        throw new ApiError({ code: 'FORBIDDEN', message: errorMessage });
      }

      // Проверяем Content-Type перед парсингом
      const contentType = response.headers.get('content-type') || '';
      const isJson = contentType.includes('application/json');
      
      // Парсим JSON ответ
      let data;
      try {
        if (isJson) {
          data = await response.json();
        } else {
          // Если не JSON, читаем как текст для диагностики
          const text = await response.text();
          
          // Проверяем, не редирект ли это
          if (text.includes('301') || text.includes('Moved Permanently') || response.status === 301) {
            throw new ApiError({
              code: 'REDIRECT_ERROR',
              message: 'Сервер вернул редирект. Проверьте URL и настройки сервера.'
            });
          }
          
          // Проверяем, не HTML ли это (ошибка сервера)
          if (text.includes('<!DOCTYPE') || text.includes('<html')) {
            throw new ApiError({
              code: 'HTML_RESPONSE',
              message: 'Сервер вернул HTML вместо JSON. Возможно, проблема с CORS или конфигурацией сервера.'
            });
          }
          
          // Пытаемся распарсить как JSON (на случай если Content-Type неправильный)
          try {
            data = JSON.parse(text);
          } catch (parseError) {
            throw new ApiError({
              code: 'PARSE_ERROR',
              message: `Сервер вернул не-JSON ответ: ${text.substring(0, 200)}`
            });
          }
        }
      } catch (e) {
        if (e instanceof ApiError) {
          throw e;
        }
        
        // Если не удалось распарсить JSON, пытаемся прочитать текст
        let errorText = 'Invalid JSON response';
        try {
          // Если response еще не прочитан, можем попробовать text()
          if (!response.bodyUsed) {
            errorText = await response.text();
          }
        } catch (textError) {
          // Игнорируем ошибку чтения текста
        }
        throw new ApiError({
          code: 'PARSE_ERROR',
          message: `Ошибка парсинга JSON: ${errorText.substring(0, 200)}`
        });
      }

      // Проверяем наличие ошибки в ответе
      // НО: для check_plan_status ошибка может быть в data.error при success: true
      // Это нормально, не бросаем исключение, а возвращаем данные как есть
      if (data.error && !allowErrorInResponse) {
        // Если это не check_plan_status, обрабатываем ошибку как обычно
        if (typeof data.error === 'object') {
          throw new ApiError({
            code: data.error.code || 'API_ERROR',
            message: data.error.message || data.error
          });
        } else {
          throw new ApiError({
            code: 'API_ERROR',
            message: data.error
          });
        }
      }

      // Если есть success: false, это тоже ошибка
      if (data.success === false) {
        throw new ApiError({
          code: 'API_ERROR',
          message: data.error || data.message || 'Request failed'
        });
      }

      return data.data || data;
    } catch (error) {
      if (error instanceof ApiError) {
        throw error;
      }
      const isAbort = error?.name === 'AbortError' || /aborted|signal is aborted/i.test(error?.message || '');
      const message = isAbort && action === 'sync_workouts'
        ? 'Синхронизация заняла слишком много времени. Попробуйте снова.'
        : (error.message || 'Network error');
      throw new ApiError({
        code: isAbort ? 'TIMEOUT' : 'NETWORK_ERROR',
        message
      });
    }
  }

  /**
   * Вход в систему
   * @param {string} username - Имя пользователя
   * @param {string} password - Пароль
   * @param {boolean} useJwt - Использовать JWT токены (для мобильных приложений)
   */
  async login(username, password, useJwt = false) {
    // Для веба используем api_v2.php (поддерживает и сессии, и JWT)
    // Для мобильных приложений используем JWT
    if (useJwt || (typeof window !== 'undefined' && window.Capacitor)) {
      return this.loginWithJwt(username, password);
    }

    // Для веба используем api_wrapper.php который проксирует к api_v2.php
    const urlParams = new URLSearchParams({ action: 'login' });
    const url = `${this.baseUrl}/api_wrapper.php?${urlParams.toString()}`;

    try {
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
      let response;
      try {
        response = await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          body: JSON.stringify({ username, password, use_jwt: false }),
          signal: controller.signal,
        });
      } finally {
        clearTimeout(timeoutId);
      }

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new ApiError({
          code: 'LOGIN_FAILED',
          message: errorData.error || errorData.message || 'Неверный логин или пароль'
        });
      }

      const data = await response.json();
      if (data.success && data.data) {
        const userData = await this.getCurrentUser();
        return { 
          success: true, 
          user: userData || {
            id: data.data.user_id,
            username: data.data.username,
            authenticated: true
          }
        };
      } else {
        throw new ApiError({
          code: 'LOGIN_FAILED',
          message: data.error || data.message || 'Неверный логин или пароль'
        });
      }
    } catch (error) {
      if (error instanceof ApiError) {
        throw error;
      }
      throw new ApiError({ code: 'LOGIN_FAILED', message: error.message });
    }
  }

  /**
   * Вход в систему с JWT токенами
   * @param {string} username - Имя пользователя
   * @param {string} password - Пароль
   */
  async loginWithJwt(username, password) {
    const urlParams = new URLSearchParams({ action: 'login' });
    const url = `${this.baseUrl}/api_wrapper.php?${urlParams.toString()}`;

    let deviceId = null;
    try {
      deviceId = await Promise.race([
        this.getOrCreateDeviceId(),
        new Promise((r) => setTimeout(() => r(null), 3000))
      ]);
    } catch {
      deviceId = null;
    }
    const body = { username, password, use_jwt: true };
    if (deviceId) body.device_id = deviceId;

    try {
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
      let response;
      try {
        response = await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
          signal: controller.signal,
        });
      } finally {
        clearTimeout(timeoutId);
      }

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new ApiError({
          code: 'LOGIN_FAILED',
          message: errorData.error || errorData.message || 'Неверный логин или пароль'
        });
      }

      const data = await response.json();
      if (data.success && data.data) {
        const { access_token, refresh_token, user_id, username: usernameFromResponse } = data.data;
        
        // Сохраняем токены
        await this.setToken(access_token, refresh_token);
        
        return {
          success: true,
          user: {
            id: user_id,
            user_id,
            username: usernameFromResponse,
            authenticated: true
          },
          access_token,
          refresh_token
        };
      } else {
        throw new ApiError({
          code: 'LOGIN_FAILED',
          message: data.error || data.message || 'Неверный логин или пароль'
        });
      }
    } catch (error) {
      if (error instanceof ApiError) {
        throw error;
      }
      throw new ApiError({ code: 'LOGIN_FAILED', message: error.message });
    }
  }

  /**
   * Выход из системы
   */
  async logout() {
    const LOGOUT_TIMEOUT_MS = 5000;
    try {
      const refreshToken = await this.getRefreshToken();
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), LOGOUT_TIMEOUT_MS);
      try {
        if (refreshToken) {
          const urlParams = new URLSearchParams({ action: 'logout' });
          const url = `${this.baseUrl}/api_wrapper.php?${urlParams.toString()}`;
          await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ refresh_token: refreshToken }),
            signal: controller.signal,
          });
        } else {
          const logoutUrl = `${this.baseUrl}/api_wrapper.php?action=logout`;
          await fetch(logoutUrl, {
            method: 'POST',
            credentials: 'include',
            signal: controller.signal,
          });
        }
      } catch (_) {
        // Таймаут или сетевая ошибка — продолжаем очистку
      } finally {
        clearTimeout(timeoutId);
      }
    } finally {
      await this.setToken(null, null);
    }
  }

  /**
   * Запросить сброс пароля (отправит письмо на email)
   * @param {string} email - Email пользователя
   * @returns {Promise<{success: boolean, sent: boolean}>}
   */
  async requestResetPassword(email) {
    const url = `${this.baseUrl}/api_wrapper.php?action=request_password_reset`;
    const response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ email: (email || '').trim() }),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new ApiError({
        code: 'RESET_FAILED',
        message: data.error || 'Не удалось запросить сброс пароля',
      });
    }
    return {
      success: data.success,
      sent: data.data?.sent ?? false,
      message: data.data?.message ?? null,
      email: data.data?.email ?? null,
    };
  }

  /**
   * Подтвердить сброс пароля по токену
   * @param {string} token - Токен из ссылки
   * @param {string} newPassword - Новый пароль
   */
  async confirmResetPassword(token, newPassword) {
    const url = `${this.baseUrl}/api_wrapper.php?action=confirm_password_reset`;
    const response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({
        token: (token || '').trim(),
        new_password: (newPassword || '').trim(),
      }),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new ApiError({
        code: 'RESET_FAILED',
        message: data.error || 'Не удалось сменить пароль',
      });
    }
    return { success: data.success };
  }

  /**
   * Отправить код подтверждения на email (шаг перед регистрацией).
   */
  async sendVerificationCode(email) {
    const url = `${this.baseUrl}/register_api.php`;
    const response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ action: 'send_verification_code', email: (email || '').trim() }),
    });
    const data = await response.json().catch(() => ({}));
    if (!data.success) {
      throw new ApiError({ code: 'VERIFICATION_SEND_FAILED', message: data.error || 'Не удалось отправить код' });
    }
    return { success: true, message: data.message };
  }

  /**
   * Минимальная регистрация (логин, email, пароль, код из письма). После успеха — автологин.
   */
  async registerMinimal({ username, email, password, verification_code }) {
    const registerUrl = `${this.baseUrl}/register_api.php`;
    const payload = { username, email, password, register_minimal: true, verification_code: (verification_code || '').trim() };
    try {
      const response = await fetch(registerUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify(payload),
      });
      const contentType = response.headers.get('content-type');
      if (contentType && contentType.includes('application/json')) {
        const data = await response.json();
        if (data.success) {
          const user = await this.getCurrentUser();
          return {
            success: true,
            user: user ?? data.user,
            plan_message: data.plan_message ?? null,
          };
        }
        throw new ApiError({
          code: 'REGISTRATION_FAILED',
          message: data.error || 'Ошибка регистрации',
          attempts_left: data.attempts_left,
        });
      }
      throw new ApiError({ message: `Registration failed: ${await response.text()}` });
    } catch (error) {
      if (error instanceof ApiError) throw error;
      throw new ApiError({ code: 'REGISTRATION_FAILED', message: error.message });
    }
  }

  /**
   * Регистрация нового пользователя (полная форма)
   */
  async register(userData) {
    const registerUrl = `${this.baseUrl}/register_api.php`;

    try {
      const response = await fetch(registerUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        credentials: 'include', // ВАЖНО: для передачи cookies (PHP сессии)
        body: JSON.stringify(userData),
      });

      const contentType = response.headers.get('content-type');
      if (contentType && contentType.includes('application/json')) {
        const data = await response.json();
        if (data.success) {
          // Сессия установлена через cookies, получаем данные пользователя
          const user = await this.getCurrentUser();
          return {
            success: true,
            user: user ?? data.user,
            plan_message: data.plan_message ?? null,
          };
        } else {
          throw new ApiError({ 
            code: 'REGISTRATION_FAILED', 
            message: data.error || 'Ошибка регистрации' 
          });
        }
      } else {
        const errorText = await response.text();
        throw new ApiError({ message: `Registration failed: ${errorText}` });
      }
    } catch (error) {
      if (error instanceof ApiError) {
        throw error;
      }
      throw new ApiError({ code: 'REGISTRATION_FAILED', message: error.message });
    }
  }

  async assessGoal(formData) {
    return this.request('assess_goal', formData, 'POST');
  }

  /**
   * Завершение специализации (второй этап после минимальной регистрации)
   */
  async completeSpecialization(payload) {
    const url = `${this.baseUrl}/complete_specialization_api.php`;
    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify(payload),
      });
      const contentType = response.headers.get('content-type');
      if (contentType && contentType.includes('application/json')) {
        const data = await response.json();
        if (data.success) {
          return {
            success: true,
            plan_message: data.plan_message ?? null,
            onboarding_completed: data.onboarding_completed ?? 1,
          };
        }
        throw new ApiError({ code: 'SPECIALIZATION_FAILED', message: data.error || 'Ошибка сохранения' });
      }
      throw new ApiError({ message: `Specialization failed: ${await response.text()}` });
    } catch (error) {
      if (error instanceof ApiError) throw error;
      throw new ApiError({ code: 'SPECIALIZATION_FAILED', message: error.message });
    }
  }

  /**
   * Валидация поля регистрации
   */
  async validateField(field, value) {
    const validateUrl = `${this.baseUrl}/register_api.php?action=validate_field&field=${encodeURIComponent(field)}&value=${encodeURIComponent(value)}`;

    try {
      const response = await fetch(validateUrl, {
        method: 'GET',
        credentials: 'include',
      });

      const data = await response.json();
      return data;
    } catch (error) {
      return { valid: false, message: 'Ошибка валидации' };
    }
  }

  /**
   * Получить текущего пользователя
   * @param {{ throwOnNetworkError?: boolean }} opts — если true, сетевые/timeout ошибки пробрасываются наружу
   */
  async getCurrentUser(opts = {}) {
    try {
      const authCheck = await this.request('check_auth', {}, 'GET');
      
      const data = authCheck?.data || authCheck;
      const isAuthenticated = data?.authenticated;
      const userId = data?.user_id;
      const username = data?.username;
      const name = data?.name ?? null;
      const avatarPath = data?.avatar_path ?? null;
      const role = data?.role ?? 'user';
      const onboardingCompleted = data?.onboarding_completed !== undefined ? !!data.onboarding_completed : false;
      const timezone = data?.timezone ?? null;
      const trainingMode = data?.training_mode ?? 'ai';

      if (isAuthenticated) {
        return {
          authenticated: true,
          user_id: userId,
          username: username,
          role,
          onboarding_completed: onboardingCompleted,
          training_mode: trainingMode,
          ...(name != null && { name }),
          ...(avatarPath != null && avatarPath !== '' && { avatar_path: avatarPath }),
          ...(timezone != null && timezone !== '' && { timezone })
        };
      }
      
      return null;
    } catch (error) {
      if (opts.throwOnNetworkError &&
          (error.code === 'NETWORK_ERROR' || error.code === 'TIMEOUT')) {
        throw error;
      }

      if (error.code === 'PARSE_ERROR' || error.code === 'HTML_RESPONSE' || error.code === 'REDIRECT_ERROR') {
        console.error('Error checking auth (server response issue):', error.message);
        return null;
      }
      
      if (error.code === 'UNAUTHORIZED' || error.code === 'NOT_AUTHENTICATED' || 
          error.code === 'FORBIDDEN' || error.message?.includes('авторизац') ||
          error.message?.includes('Требуется авторизация')) {
        return null;
      }
      console.error('Error checking auth:', error);
      return null;
    }
  }

  // ========== ПЛАНЫ ТРЕНИРОВОК ==========

  /**
   * Контекст просмотра чужого профиля (для load, get_day, get_all_workouts_summary и т.д.)
   * @param {{ slug: string, token?: string }} viewContext
   */
  _viewParams(viewContext) {
    if (!viewContext?.slug) return {};
    const p = { view: 'user', slug: viewContext.slug };
    if (viewContext.token) p.token = viewContext.token;
    return p;
  }

  async getUserBySlug(slug, token = null) {
    const params = { slug: slug.startsWith('@') ? slug.slice(1) : slug };
    if (token) params.token = token;
    return this.request('get_user_by_slug', params, 'GET');
  }

  async getPlan(userId = null, viewContext = null) {
    const params = userId ? { user_id: userId } : {};
    if (viewContext) Object.assign(params, this._viewParams(viewContext));
    return this.request('load', params, 'GET');
  }

  async savePlan(planData) {
    return this.request('save', { plan: JSON.stringify(planData) }, 'POST');
  }

  // ========== ТРЕНИРОВКИ ==========

  async getDay(date, viewContext = null) {
    const params = { date };
    if (viewContext) Object.assign(params, this._viewParams(viewContext));
    return this.request('get_day', params, 'GET');
  }

  /**
   * Сохранить результат тренировки (отметить выполненной).
   * @param {Object} data — { date, week, day, activity_type_id?, result_distance?, result_time?, notes?, is_successful?, avg_heart_rate?, ... }
   */
  async saveResult(data) {
    const csrfRes = await this.request('get_csrf_token', {}, 'GET');
    const csrfToken = csrfRes?.csrf_token ?? csrfRes?.data?.csrf_token;
    const body = { ...data };
    if (csrfToken) body.csrf_token = csrfToken;
    if (body.activity_type_id == null) body.activity_type_id = 1;
    return this.request('save_result', body, 'POST');
  }

  async getResult(date, viewContext = null) {
    const params = { date };
    if (viewContext) Object.assign(params, this._viewParams(viewContext));
    return this.request('get_result', params, 'GET');
  }

  /**
   * Загрузить тренировку из GPX/TCX файла
   * @param {File} file
   * @param {{ date?: string }} opts - date в формате Y-m-d
   */
  async uploadWorkout(file, opts = {}) {
    const csrfRes = await this.request('get_csrf_token', {}, 'GET');
    const csrfToken = csrfRes?.csrf_token ?? csrfRes?.data?.csrf_token;
    const formData = new FormData();
    formData.append('file', file);
    formData.append('date', opts.date || new Date().toISOString().slice(0, 10));
    if (csrfToken) formData.append('csrf_token', csrfToken);
    const token = await this.getToken();
    const headers = {};
    if (token) headers['Authorization'] = `Bearer ${token}`;
    const url = `${this.baseUrl}/api_wrapper.php?action=upload_workout`;
    const response = await fetch(url, {
      method: 'POST',
      headers,
      credentials: typeof window !== 'undefined' && !window.Capacitor ? 'include' : 'omit',
      body: formData,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new ApiError({ code: 'UPLOAD_FAILED', message: data.error || 'Ошибка загрузки' });
    }
    if (data.success === false) {
      throw new ApiError({ code: 'UPLOAD_FAILED', message: data.error || 'Ошибка загрузки' });
    }
    return data.data || data;
  }

  async getAllResults(viewContext = null) {
    const params = viewContext ? this._viewParams(viewContext) : {};
    return this.request('get_all_results', params, 'GET');
  }

  async reset(date) {
    return this.request('reset', { date }, 'POST');
  }

  // ========== СТАТИСТИКА ==========

  async getStats(viewContext = null) {
    const params = viewContext ? this._viewParams(viewContext) : {};
    return this.request('stats', params, 'GET');
  }

  async getAllWorkoutsSummary(viewContext = null) {
    const params = viewContext ? this._viewParams(viewContext) : {};
    return this.request('get_all_workouts_summary', params, 'GET');
  }

  /**
   * Список всех тренировок (каждая отдельно, без группировки по дню)
   * @param {{ slug?: string, token?: string }} viewContext — для просмотра чужого профиля
   * @param {number} limit — макс. записей (по умолчанию 500)
   */
  async getAllWorkoutsList(viewContext = null, limit = 500) {
    const params = viewContext ? this._viewParams(viewContext) : {};
    if (limit) params.limit = limit;
    return this.request('get_all_workouts_list', params, 'GET');
  }

  // ========== ИНТЕГРАЦИИ (Huawei, Garmin, Strava) ==========

  async getIntegrationOAuthUrl(provider) {
    return this.request('integration_oauth_url', { provider }, 'GET');
  }

  async syncWorkouts(provider) {
    const csrfRes = await this.request('get_csrf_token', {}, 'GET');
    const csrfToken = csrfRes?.csrf_token ?? csrfRes?.data?.csrf_token;
    return this.request('sync_workouts', { provider, csrf_token: csrfToken }, 'POST');
  }

  async getIntegrationsStatus() {
    return this.request('integrations_status', {}, 'GET');
  }

  async unlinkIntegration(provider) {
    const csrfRes = await this.request('get_csrf_token', {}, 'GET');
    const csrfToken = csrfRes?.csrf_token ?? csrfRes?.data?.csrf_token;
    return this.request('unlink_integration', { provider, csrf_token: csrfToken }, 'POST');
  }

  async getStravaTokenError() {
    return this.request('strava_token_error', {}, 'GET');
  }

  /**
   * Получить timeline данные тренировки (пульс, темп по времени)
   * @param {number} workoutId ID тренировки
   * @returns {Promise<Object>} Timeline данные
   */
  async getWorkoutTimeline(workoutId) {
    return this.request('get_workout_timeline', { workout_id: workoutId }, 'GET');
  }

  // ========== АДАПТАЦИЯ ==========

  async runAdaptation() {
    return this.request('run_weekly_adaptation', {}, 'GET');
  }

  async regeneratePlan() {
    return this.request('regenerate_plan_with_progress', {}, 'POST');
  }

  async recalculatePlan(reason = null) {
    const params = {};
    if (reason) params.reason = reason;
    return this.request('recalculate_plan', params, 'POST');
  }

  async generateNextPlan(goals = null) {
    const params = {};
    if (goals) params.goals = goals;
    return this.request('generate_next_plan', params, 'POST');
  }

  /**
   * Проверка статуса плана (есть ли план, есть ли ошибка)
   */
  async checkPlanStatus(userId = null) {
    const params = userId ? { user_id: userId } : {};
    const response = await this.request('check_plan_status', params, 'GET');
    // API может вернуть success: true с error в ответе, это нормально
    return response;
  }

  // ========== УПРАВЛЕНИЕ НЕДЕЛЯМИ ==========

  async deleteWeek(weekNumber) {
    return this.request('delete_week', { week: weekNumber }, 'POST');
  }

  async addWeek(weekData) {
    return this.request('add_week', weekData, 'POST');
  }

  /**
   * Добавить тренировку на дату (календарная модель).
   * @param {{ date: string, type: string, description?: string, is_key_workout?: boolean }} data
   */
  async addTrainingDayByDate(data) {
    return this.request('add_training_day_by_date', data, 'POST');
  }

  /**
   * Удалить выполненную тренировку (workout / manual log).
   * @param {number} workoutId
   * @param {boolean} [isManual=false]
   */
  async deleteWorkout(workoutId, isManual = false) {
    const csrfRes = await this.request('get_csrf_token', {}, 'GET');
    const csrfToken = csrfRes?.csrf_token ?? csrfRes?.data?.csrf_token;
    if (!csrfToken) {
      throw new ApiError({ code: 'CSRF_MISSING', message: 'Не удалось получить токен безопасности. Обновите страницу.' });
    }
    return this.request('delete_workout', { workout_id: workoutId, is_manual: isManual, csrf_token: csrfToken }, 'POST');
  }

  /**
   * Удалить тренировку из плана по id дня.
   * @param {number} dayId - id записи в training_plan_days
   */
  async deleteTrainingDay(dayId) {
    const csrfRes = await this.request('get_csrf_token', {}, 'GET');
    const csrfToken = csrfRes?.csrf_token ?? csrfRes?.data?.csrf_token;
    if (!csrfToken) {
      throw new ApiError({ code: 'CSRF_MISSING', message: 'Не удалось получить токен безопасности. Обновите страницу.' });
    }
    return this.request('delete_training_day', { day_id: dayId, csrf_token: csrfToken }, 'POST');
  }

  /**
   * Обновить тренировку в плане по id дня.
   * @param {number} dayId - id записи в training_plan_days
   * @param {object} data - { type, description?, is_key_workout? }
   */
  async updateTrainingDay(dayId, data) {
    const csrfRes = await this.request('get_csrf_token', {}, 'GET');
    const csrfToken = csrfRes?.csrf_token ?? csrfRes?.data?.csrf_token;
    if (!csrfToken) {
      throw new ApiError({ code: 'CSRF_MISSING', message: 'Не удалось получить токен безопасности. Обновите страницу.' });
    }
    return this.request('update_training_day', {
      day_id: dayId,
      type: data.type,
      description: data.description,
      is_key_workout: data.is_key_workout != null ? (data.is_key_workout ? 1 : 0) : undefined,
      csrf_token: csrfToken,
    }, 'POST');
  }

  // ========== АДМИНКА ==========

  /** Список пользователей (только для admin) */
  async getAdminUsers(params = {}) {
    const searchParams = new URLSearchParams();
    if (params.page != null) searchParams.set('page', params.page);
    if (params.per_page != null) searchParams.set('per_page', params.per_page);
    if (params.search != null && params.search !== '') searchParams.set('search', params.search);
    const query = searchParams.toString();
    return this.request('admin_list_users', query ? Object.fromEntries(searchParams) : {}, 'GET');
  }

  /** Один пользователь по ID */
  async getAdminUser(userId) {
    return this.request('admin_get_user', { user_id: userId }, 'GET');
  }

  /** Обновить пользователя (роль, email). В body передать csrf_token. */
  async updateAdminUser(payload) {
    return this.request('admin_update_user', payload, 'POST');
  }

  /** Удалить пользователя (только admin). В body передать user_id и csrf_token. */
  async deleteUser(payload) {
    return this.request('delete_user', payload, 'POST');
  }

  /** Настройки сайта */
  async getAdminSettings() {
    return this.request('admin_get_settings', {}, 'GET');
  }

  /** Сохранить настройки сайта. В payload включить csrf_token и settings. */
  async updateAdminSettings(payload) {
    return this.request('admin_update_settings', payload, 'POST');
  }

  /**
   * Публичные настройки сайта (без авторизации).
   * Для проверки maintenance_mode, registration_enabled, site_name и т.д.
   */
  async getSiteSettings() {
    return this.request('get_site_settings', {}, 'GET');
  }

  // ========== ЧАТ ==========

  /**
   * Получить сообщения чата
   * @param {string} type - 'ai' | 'admin'
   * @param {number} limit
   * @param {number} offset
   */
  async chatGetMessages(type = 'ai', limit = 50, offset = 0) {
    return this.request('chat_get_messages', { type, limit, offset }, 'GET');
  }

  /**
   * Отправить сообщение AI (без streaming)
   * @param {string} content
   */
  async chatSendMessage(content) {
    return this.request('chat_send_message', { content: (content || '').trim() }, 'POST');
  }

  /**
   * Отправить сообщение AI с streaming
   * @param {string} content
   * @param {function(string)} onChunk - callback для каждого чанка
   * @param {object} opts - { onFirstChunk?: () => void, timeoutMs?: number, signal?: AbortSignal }
   */
  async chatSendMessageStream(content, onChunk, opts = {}) {
    const { onFirstChunk, onPlanUpdated, onPlanRecalculating, onPlanGeneratingNext, timeoutMs = 180000, signal: externalSignal } = opts;
    const urlParams = new URLSearchParams({ action: 'chat_send_message_stream' });
    const url = `${this.baseUrl}/api_wrapper.php?${urlParams.toString()}`;

    const token = await this.getToken();
    const headers = { 'Content-Type': 'application/json' };
    if (token) headers['Authorization'] = `Bearer ${token}`;

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
    const onExternalAbort = () => controller.abort();
    if (externalSignal) {
      if (externalSignal.aborted) {
        clearTimeout(timeoutId);
        throw new DOMException('Aborted', 'AbortError');
      }
      externalSignal.addEventListener('abort', onExternalAbort, { once: true });
    }

    const response = await fetch(url, {
      method: 'POST',
      headers,
      credentials: 'include',
      body: JSON.stringify({ content: (content || '').trim() }),
      signal: controller.signal,
    });

    clearTimeout(timeoutId);

    if (!response.ok) {
      const err = await response.json().catch(() => ({}));
      throw new ApiError({ code: 'CHAT_FAILED', message: err.error || 'Ошибка чата' });
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let firstChunkFired = false;
    let fullContent = '';

    try {
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split('\n');
        buffer = lines.pop() || '';
        for (const line of lines) {
          const trimmed = line.trim();
          if (!trimmed) continue;
          try {
            const obj = JSON.parse(trimmed);
            if (obj.error) {
              throw new ApiError({ code: 'CHAT_FAILED', message: obj.error });
            }
            if (obj.chunk) {
              fullContent += obj.chunk;
              if (typeof onChunk === 'function') {
                if (!firstChunkFired && typeof onFirstChunk === 'function') {
                  firstChunkFired = true;
                  onFirstChunk();
                }
                onChunk(obj.chunk);
              }
            }
            if (obj.plan_updated && typeof onPlanUpdated === 'function') {
              onPlanUpdated();
            }
            if (obj.plan_recalculating && typeof onPlanRecalculating === 'function') {
              onPlanRecalculating();
            }
            if (obj.plan_generating_next && typeof onPlanGeneratingNext === 'function') {
              onPlanGeneratingNext();
            }
          } catch (e) {
            if (e instanceof ApiError) throw e;
          }
        }
      }
      if (buffer.trim()) {
        const obj = JSON.parse(buffer.trim());
        if (obj.error) throw new ApiError({ code: 'CHAT_FAILED', message: obj.error });
        if (obj.chunk) {
          fullContent += obj.chunk;
          if (typeof onChunk === 'function') {
            if (!firstChunkFired && typeof onFirstChunk === 'function') onFirstChunk();
            onChunk(obj.chunk);
          }
        }
        if (obj.plan_updated && typeof onPlanUpdated === 'function') onPlanUpdated();
        if (obj.plan_recalculating && typeof onPlanRecalculating === 'function') onPlanRecalculating();
        if (obj.plan_generating_next && typeof onPlanGeneratingNext === 'function') onPlanGeneratingNext();
      }
    } finally {
      reader.releaseLock?.();
    }
    return fullContent;
  }

  /**
   * Отправить сообщение администрации (из чата «От администрации»)
   * @param {string} content
   */
  async chatSendMessageToAdmin(content) {
    return this.request('chat_send_message_to_admin', { content: (content || '').trim() }, 'POST');
  }

  /**
   * Список диалогов: пользователи, которые писали мне через «Написать»
   */
  async chatGetDirectDialogs() {
    const res = await this.request('chat_get_direct_dialogs', {}, 'GET');
    return Array.isArray(res?.users) ? res.users : [];
  }

  /**
   * Сообщения между текущим пользователем и другим (диалог «Написать»)
   * @param {number} targetUserId ID собеседника
   * @param {number} limit
   * @param {number} offset
   */
  async chatGetDirectMessages(targetUserId, limit = 50, offset = 0) {
    return this.request('chat_get_direct_messages', { target_user_id: targetUserId, limit, offset }, 'GET');
  }

  /**
   * Отправить сообщение пользователю (от своего имени)
   * @param {number} targetUserId ID получателя
   * @param {string} content Текст сообщения
   */
  async chatSendMessageToUser(targetUserId, content) {
    return this.request('chat_send_message_to_user', { target_user_id: targetUserId, content: (content || '').trim() }, 'POST');
  }

  /**
   * Очистить direct-диалог с пользователем
   * @param {number} targetUserId ID собеседника
   */
  async chatClearDirectDialog(targetUserId) {
    return this.request('chat_clear_direct_dialog', { target_user_id: targetUserId }, 'POST');
  }

  /**
   * Отметить сообщения как прочитанные
   * @param {number} conversationId
   */
  async chatMarkRead(conversationId) {
    return this.request('chat_mark_read', { conversation_id: conversationId }, 'POST');
  }

  /**
   * Очистить чат с AI
   */
  async chatClearAi() {
    return this.request('chat_clear_ai', {}, 'POST');
  }

  /**
   * Отметить все сообщения во всех чатах как прочитанные
   */
  async chatMarkAllRead() {
    return this.request('chat_mark_all_read', {}, 'POST');
  }

  /**
   * Админ: отметить все сообщения от пользователей как прочитанные
   */
  async chatAdminMarkAllRead() {
    return this.request('chat_admin_mark_all_read', {}, 'POST');
  }

  /**
   * Админ: отправить сообщение пользователю
   * @param {number} userId - ID пользователя
   * @param {string} content - текст сообщения
   */
  async chatAdminSendMessage(userId, content) {
    return this.request('chat_admin_send_message', { user_id: userId, content: (content || '').trim() }, 'POST');
  }

  /**
   * Админ: список пользователей, которые писали в admin-чат
   */
  async getAdminChatUsers() {
    const res = await this.request('chat_admin_chat_users', {}, 'GET');
    return Array.isArray(res?.users) ? res.users : [];
  }

  /**
   * Админ: получить сообщения пользователя (admin-чат)
   * @param {number} userId - ID пользователя
   * @param {number} limit
   * @param {number} offset
   */
  async chatAdminGetMessages(userId, limit = 50, offset = 0) {
    return this.request('chat_admin_get_messages', { user_id: userId, limit, offset }, 'GET');
  }

  /**
   * Админ: отметить диалог с пользователем как прочитанный (при открытии чата с ним)
   * @param {number} userId - ID пользователя
   */
  async chatAdminMarkConversationRead(userId) {
    return this.request('chat_admin_mark_conversation_read', { user_id: userId }, 'POST');
  }

  /**
   * Добавить сообщение от AI пользователю (досыл, напоминание). Только для админа.
   * @param {number} userId - ID пользователя
   * @param {string} content - текст сообщения
   */
  async chatAddAIMessage(userId, content) {
    return this.request('chat_add_ai_message', { user_id: userId, content: (content || '').trim() }, 'POST');
  }

  /**
   * Админ: непрочитанные сообщения от пользователей (для уведомлений)
   * @param {number} limit
   */
  async chatAdminGetUnreadNotifications(limit = 10) {
    const res = await this.request('chat_admin_unread_notifications', { limit }, 'GET');
    return Array.isArray(res?.messages) ? res.messages : [];
  }

  /**
   * Админ: массовая рассылка сообщения
   * @param {string} content - текст сообщения
   * @param {number[]} [userIds] - опционально; если не указан — всем пользователям
   */
  async chatAdminBroadcast(content, userIds = null) {
    const body = { content: (content || '').trim() };
    if (Array.isArray(userIds) && userIds.length > 0) {
      body.user_ids = userIds;
    }
    return this.request('chat_admin_broadcast', body, 'POST');
  }

  /**
   * Получить список закрытых уведомлений (синхронизация между устройствами)
   */
  async getNotificationsDismissed() {
    const res = await this.request('notifications_dismissed', {}, 'GET');
    return Array.isArray(res?.dismissed) ? res.dismissed : [];
  }

  /**
   * Закрыть уведомление
   * @param {string} notificationId - например "chat_123", "workout_2025-02-07"
   */
  async dismissNotification(notificationId) {
    return this.request('notifications_dismiss', { notification_id: String(notificationId || '') }, 'POST');
  }
}

export default ApiClient;
export { ApiError };
