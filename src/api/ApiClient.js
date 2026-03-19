/**
 * Универсальный API клиент для React (веб) и React Native (мобильное)
 * Один код для всех платформ
 */

import BiometricService from '../services/BiometricService';
import PinAuthService from '../services/PinAuthService';
import TokenStorageService, { isNativeCapacitor } from '../services/TokenStorageService';
import { ApiError, buildApiError, extractRetryAfter } from './apiError';
import {
  login as performLogin,
  loginWithJwt as performLoginWithJwt,
  logout as performLogout,
  requestResetPassword as performRequestResetPassword,
  confirmResetPassword as performConfirmResetPassword,
  sendVerificationCode as performSendVerificationCode,
  registerMinimal as performRegisterMinimal,
  register as performRegister,
  completeSpecialization as performCompleteSpecialization,
  validateField as performValidateField,
} from './authApi';
import {
  getPlan as performGetPlan,
  savePlan as performSavePlan,
  regeneratePlan as performRegeneratePlan,
  recalculatePlan as performRecalculatePlan,
  generateNextPlan as performGenerateNextPlan,
  checkPlanStatus as performCheckPlanStatus,
  clearPlan as performClearPlan,
} from './planApi';
import {
  getUserBySlug as performGetUserBySlug,
  getDay as performGetDay,
  saveResult as performSaveResult,
  getResult as performGetResult,
  uploadWorkout as performUploadWorkout,
  getAllResults as performGetAllResults,
  resetWorkout as performResetWorkout,
  deleteWeek as performDeleteWeek,
  addWeek as performAddWeek,
  addTrainingDayByDate as performAddTrainingDayByDate,
  deleteWorkout as performDeleteWorkout,
  deleteTrainingDay as performDeleteTrainingDay,
  copyDay as performCopyDay,
  copyWeek as performCopyWeek,
  getDayNotes as performGetDayNotes,
  saveDayNote as performSaveDayNote,
  deleteDayNote as performDeleteDayNote,
  getWeekNotes as performGetWeekNotes,
  saveWeekNote as performSaveWeekNote,
  deleteWeekNote as performDeleteWeekNote,
  getNoteCounts as performGetNoteCounts,
  getPlanNotifications as performGetPlanNotifications,
  markPlanNotificationRead as performMarkPlanNotificationRead,
  markAllPlanNotificationsRead as performMarkAllPlanNotificationsRead,
  updateTrainingDay as performUpdateTrainingDay,
} from './workoutApi';
import {
  getStats as performGetStats,
  getAllWorkoutsSummary as performGetAllWorkoutsSummary,
  getAllWorkoutsList as performGetAllWorkoutsList,
  getRacePrediction as performGetRacePrediction,
  getIntegrationOAuthUrl as performGetIntegrationOAuthUrl,
  syncWorkouts as performSyncWorkouts,
  getIntegrationsStatus as performGetIntegrationsStatus,
  unlinkIntegration as performUnlinkIntegration,
  getStravaTokenError as performGetStravaTokenError,
  getWorkoutTimeline as performGetWorkoutTimeline,
  runAdaptation as performRunAdaptation,
} from './statsApi';
import {
  getAdminUsers as performGetAdminUsers,
  getAdminUser as performGetAdminUser,
  updateAdminUser as performUpdateAdminUser,
  deleteUser as performDeleteUser,
  getAdminSettings as performGetAdminSettings,
  updateAdminSettings as performUpdateAdminSettings,
  getSiteSettings as performGetSiteSettings,
} from './adminApi';
import {
  chatGetMessages as performChatGetMessages,
  chatSendMessage as performChatSendMessage,
  chatSendMessageStream as performChatSendMessageStream,
  chatSendMessageToAdmin as performChatSendMessageToAdmin,
  chatGetDirectDialogs as performChatGetDirectDialogs,
  chatGetDirectMessages as performChatGetDirectMessages,
  chatSendMessageToUser as performChatSendMessageToUser,
  chatClearDirectDialog as performChatClearDirectDialog,
  chatMarkRead as performChatMarkRead,
  chatClearAi as performChatClearAi,
  chatMarkAllRead as performChatMarkAllRead,
  chatAdminMarkAllRead as performChatAdminMarkAllRead,
  chatAdminSendMessage as performChatAdminSendMessage,
  getAdminChatUsers as performGetAdminChatUsers,
  chatAdminGetMessages as performChatAdminGetMessages,
  chatAdminMarkConversationRead as performChatAdminMarkConversationRead,
  chatAddAIMessage as performChatAddAIMessage,
  chatAdminGetUnreadNotifications as performChatAdminGetUnreadNotifications,
  chatAdminBroadcast as performChatAdminBroadcast,
  getNotificationsDismissed as performGetNotificationsDismissed,
  dismissNotification as performDismissNotification,
} from './chatApi';
import {
  listCoaches as performListCoaches,
  requestCoach as performRequestCoach,
  getCoachRequests as performGetCoachRequests,
  acceptCoachRequest as performAcceptCoachRequest,
  rejectCoachRequest as performRejectCoachRequest,
  getMyCoaches as performGetMyCoaches,
  removeCoach as performRemoveCoach,
  applyCoach as performApplyCoach,
  getCoachAthletes as performGetCoachAthletes,
  getCoachPricing as performGetCoachPricing,
  updateCoachPricing as performUpdateCoachPricing,
  getCoachGroups as performGetCoachGroups,
  saveCoachGroup as performSaveCoachGroup,
  deleteCoachGroup as performDeleteCoachGroup,
  getGroupMembers as performGetGroupMembers,
  updateGroupMembers as performUpdateGroupMembers,
  getAthleteGroups as performGetAthleteGroups,
  getCoachApplications as performGetCoachApplications,
  approveCoachApplication as performApproveCoachApplication,
  rejectCoachApplication as performRejectCoachApplication,
} from './coachApi';

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
        this.baseUrl = 'https://planrun.ru/api';
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
        // Обязательно await Preferences — иначе при быстром перезапуске токены не успеют сохраниться.
        // SecureStorage пишется в фоне внутри saveTokens.
        await TokenStorageService.saveTokens(token, refreshToken).catch((e) => {
          if (process.env.NODE_ENV !== 'production') {
            console.warn('[ApiClient] TokenStorage save:', e?.message);
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
      // На native: TokenStorageService первым (localStorage может очищаться при kill приложения)
      try {
        const stored = await TokenStorageService.getTokens();
        if (stored?.accessToken && stored?.refreshToken) {
          this.token = stored.accessToken;
          this.refreshToken = stored.refreshToken;
          token = stored.accessToken;
          if (typeof localStorage !== 'undefined') {
            localStorage.setItem('auth_token', stored.accessToken);
            localStorage.setItem('refresh_token', stored.refreshToken);
          }
        }
      } catch (e) {
        // игнорируем
      }
      if (!token && typeof localStorage !== 'undefined') {
        const stored = localStorage.getItem('auth_token');
        const refresh = localStorage.getItem('refresh_token');
        if (stored && refresh) {
          this.token = stored;
          this.refreshToken = refresh;
          token = stored;
        }
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
      try {
        const stored = await TokenStorageService.getTokens();
        if (stored?.accessToken && stored?.refreshToken) {
          this.token = stored.accessToken;
          this.refreshToken = stored.refreshToken;
          if (typeof localStorage !== 'undefined') {
            localStorage.setItem('auth_token', stored.accessToken);
            localStorage.setItem('refresh_token', stored.refreshToken);
          }
          return stored.refreshToken;
        }
      } catch (e) {
        // игнорируем
      }
      if (typeof localStorage !== 'undefined') {
        const stored = localStorage.getItem('refresh_token');
        if (stored) {
          this.refreshToken = stored;
          return stored;
        }
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
                await BiometricService.saveTokens(access_token, refresh_token).catch((e) => {
                  if (process.env.NODE_ENV !== 'production') {
                    console.warn('[ApiClient] Biometric saveTokens:', e?.message);
                  }
                });
              }
              // PinAuthService требует PIN — обновляется при успешном pinLogin в useAuthStore
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
  async request(action, params = {}, method = 'GET', extraUrlParams = {}) {
    const token = await this.getToken();

    // Формируем URL - action всегда в URL, extraUrlParams тоже в URL (для view/slug в POST)
    const urlParams = new URLSearchParams({ action, ...extraUrlParams });
    
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

      if (response.status === 429) {
        let errorData = {};
        try {
          errorData = await response.json();
        } catch (_) {}
        throw buildApiError({
          response,
          data: errorData,
          code: 'RATE_LIMITED',
          message: 'Слишком много запросов. Попробуйте позже.'
        });
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
          message: data.error || data.message || 'Request failed',
          retry_after: extractRetryAfter(response, data, data.error || data.message || 'Request failed'),
          status: response.status
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
    return performLogin(this, username, password, useJwt);
  }

  /**
   * Вход в систему с JWT токенами
   * @param {string} username - Имя пользователя
   * @param {string} password - Пароль
   */
  async loginWithJwt(username, password) {
    return performLoginWithJwt(this, username, password);
  }

  /**
   * Выход из системы
   */
  async logout() {
    return performLogout(this);
  }

  /**
   * Запросить сброс пароля (отправит письмо на email)
   * @param {string} email - Email пользователя
   * @returns {Promise<{success: boolean, sent: boolean}>}
   */
  async requestResetPassword(email) {
    return performRequestResetPassword(this, email);
  }

  /**
   * Подтвердить сброс пароля по токену
   * @param {string} token - Токен из ссылки
   * @param {string} newPassword - Новый пароль
   */
  async confirmResetPassword(token, newPassword) {
    return performConfirmResetPassword(this, token, newPassword);
  }

  /**
   * Отправить код подтверждения на email (шаг перед регистрацией).
   */
  async sendVerificationCode(email) {
    return performSendVerificationCode(this, email);
  }

  /**
   * Минимальная регистрация (логин, email, пароль, код из письма). После успеха — автологин.
   */
  async registerMinimal({ username, email, password, verification_code }) {
    return performRegisterMinimal(this, { username, email, password, verification_code });
  }

  /**
   * Регистрация нового пользователя (полная форма)
   */
  async register(userData) {
    return performRegister(this, userData);
  }

  async assessGoal(formData) {
    return this.request('assess_goal', formData, 'POST');
  }

  /**
   * Завершение специализации (второй этап после минимальной регистрации)
   */
  async completeSpecialization(payload) {
    return performCompleteSpecialization(this, payload);
  }

  /**
   * Валидация поля регистрации
   */
  async validateField(field, value) {
    return performValidateField(this, field, value);
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
    return performGetUserBySlug(this, slug, token);
  }

  async getPlan(userId = null, viewContext = null) {
    return performGetPlan(this, userId, viewContext);
  }

  async savePlan(planData) {
    return performSavePlan(this, planData);
  }

  // ========== ТРЕНИРОВКИ ==========

  async getDay(date, viewContext = null) {
    return performGetDay(this, date, viewContext);
  }

  /**
   * Сохранить результат тренировки (отметить выполненной).
   * @param {Object} data — { date, week, day, activity_type_id?, result_distance?, result_time?, notes?, is_successful?, avg_heart_rate?, ... }
   */
  async saveResult(data, viewContext = null) {
    return performSaveResult(this, data, viewContext);
  }

  async getResult(date, viewContext = null) {
    return performGetResult(this, date, viewContext);
  }

  /**
   * Загрузить тренировку из GPX/TCX файла
   * @param {File} file
   * @param {{ date?: string }} opts - date в формате Y-m-d
   */
  async uploadWorkout(file, opts = {}) {
    return performUploadWorkout(this, file, opts);
  }

  async getAllResults(viewContext = null) {
    return performGetAllResults(this, viewContext);
  }

  async reset(date) {
    return performResetWorkout(this, date);
  }

  // ========== СТАТИСТИКА ==========

  async getStats(viewContext = null) {
    return performGetStats(this, viewContext);
  }

  async getAllWorkoutsSummary(viewContext = null) {
    return performGetAllWorkoutsSummary(this, viewContext);
  }

  /**
   * Список всех тренировок (каждая отдельно, без группировки по дню)
   * @param {{ slug?: string, token?: string }} viewContext — для просмотра чужого профиля
   * @param {number} limit — макс. записей (по умолчанию 500)
   */
  async getAllWorkoutsList(viewContext = null, limit = 500) {
    return performGetAllWorkoutsList(this, viewContext, limit);
  }

  async getRacePrediction(viewContext = null) {
    return performGetRacePrediction(this, viewContext);
  }

  // ========== ИНТЕГРАЦИИ (Huawei, Garmin, Strava) ==========

  async getIntegrationOAuthUrl(provider, extra = {}) {
    return performGetIntegrationOAuthUrl(this, provider, extra);
  }

  async syncWorkouts(provider) {
    return performSyncWorkouts(this, provider);
  }

  async getIntegrationsStatus() {
    return performGetIntegrationsStatus(this);
  }

  async unlinkIntegration(provider) {
    return performUnlinkIntegration(this, provider);
  }

  async getStravaTokenError() {
    return performGetStravaTokenError(this);
  }

  /**
   * Получить timeline данные тренировки (пульс, темп по времени)
   * @param {number} workoutId ID тренировки
   * @returns {Promise<Object>} Timeline данные
   */
  async getWorkoutTimeline(workoutId) {
    return performGetWorkoutTimeline(this, workoutId);
  }

  // ========== АДАПТАЦИЯ ==========

  async runAdaptation() {
    return performRunAdaptation(this);
  }

  async regeneratePlan() {
    return performRegeneratePlan(this);
  }

  async recalculatePlan(reason = null) {
    return performRecalculatePlan(this, reason);
  }

  async generateNextPlan(goals = null) {
    return performGenerateNextPlan(this, goals);
  }

  /**
   * Проверка статуса плана (есть ли план, есть ли ошибка)
   */
  async checkPlanStatus(userId = null) {
    return performCheckPlanStatus(this, userId);
  }

  /**
   * Удалить план тренировок (сгенерированный ИИ).
   * Результаты тренировок сохраняются.
   */
  async clearPlan() {
    return performClearPlan(this);
  }

  // ========== УПРАВЛЕНИЕ НЕДЕЛЯМИ ==========

  async deleteWeek(weekNumber) {
    return performDeleteWeek(this, weekNumber);
  }

  async addWeek(weekData) {
    return performAddWeek(this, weekData);
  }

  /**
   * Добавить тренировку на дату (календарная модель).
   * @param {{ date: string, type: string, description?: string, is_key_workout?: boolean }} data
   */
  async addTrainingDayByDate(data, viewContext = null) {
    return performAddTrainingDayByDate(this, data, viewContext);
  }

  /**
   * Удалить выполненную тренировку (workout / manual log).
   * @param {number} workoutId
   * @param {boolean} [isManual=false]
   */
  async deleteWorkout(workoutId, isManual = false, viewContext = null) {
    return performDeleteWorkout(this, workoutId, isManual, viewContext);
  }

  /**
   * Удалить тренировку из плана по id дня.
   * @param {number} dayId - id записи в training_plan_days
   */
  async deleteTrainingDay(dayId, viewContext = null) {
    return performDeleteTrainingDay(this, dayId, viewContext);
  }

  async copyDay(sourceDate, targetDate, viewContext = null) {
    return performCopyDay(this, sourceDate, targetDate, viewContext);
  }

  async copyWeek(sourceWeekId, targetStartDate, viewContext = null) {
    return performCopyWeek(this, sourceWeekId, targetStartDate, viewContext);
  }

  // --- Notes (заметки к дню / неделе) ---

  async getDayNotes(date, viewContext = null) {
    return performGetDayNotes(this, date, viewContext);
  }

  async saveDayNote(date, content, noteId = null, viewContext = null) {
    return performSaveDayNote(this, date, content, noteId, viewContext);
  }

  async deleteDayNote(noteId, viewContext = null) {
    return performDeleteDayNote(this, noteId, viewContext);
  }

  async getWeekNotes(weekStart, viewContext = null) {
    return performGetWeekNotes(this, weekStart, viewContext);
  }

  async saveWeekNote(weekStart, content, noteId = null, viewContext = null) {
    return performSaveWeekNote(this, weekStart, content, noteId, viewContext);
  }

  async deleteWeekNote(noteId, viewContext = null) {
    return performDeleteWeekNote(this, noteId, viewContext);
  }

  async getNoteCounts(startDate, endDate, viewContext = null) {
    return performGetNoteCounts(this, startDate, endDate, viewContext);
  }

  // --- Plan notifications ---

  async getPlanNotifications() {
    return performGetPlanNotifications(this);
  }

  async markPlanNotificationRead(notificationId) {
    return performMarkPlanNotificationRead(this, notificationId);
  }

  async markAllPlanNotificationsRead() {
    return performMarkAllPlanNotificationsRead(this);
  }

  /**
   * Обновить тренировку в плане по id дня.
   * @param {number} dayId - id записи в training_plan_days
   * @param {object} data - { type, description?, is_key_workout? }
   */
  async updateTrainingDay(dayId, data, viewContext = null) {
    return performUpdateTrainingDay(this, dayId, data, viewContext);
  }

  // ========== АДМИНКА ==========

  /** Список пользователей (только для admin) */
  async getAdminUsers(params = {}) {
    return performGetAdminUsers(this, params);
  }

  /** Один пользователь по ID */
  async getAdminUser(userId) {
    return performGetAdminUser(this, userId);
  }

  /** Обновить пользователя (роль, email). В body передать csrf_token. */
  async updateAdminUser(payload) {
    return performUpdateAdminUser(this, payload);
  }

  /** Удалить пользователя (только admin). В body передать user_id и csrf_token. */
  async deleteUser(payload) {
    return performDeleteUser(this, payload);
  }

  /** Настройки сайта */
  async getAdminSettings() {
    return performGetAdminSettings(this);
  }

  /** Сохранить настройки сайта. В payload включить csrf_token и settings. */
  async updateAdminSettings(payload) {
    return performUpdateAdminSettings(this, payload);
  }

  /**
   * Публичные настройки сайта (без авторизации).
   * Для проверки maintenance_mode, registration_enabled, site_name и т.д.
   */
  async getSiteSettings() {
    return performGetSiteSettings(this);
  }

  // ========== ЧАТ ==========

  /**
   * Получить сообщения чата
   * @param {string} type - 'ai' | 'admin'
   * @param {number} limit
   * @param {number} offset
   */
  async chatGetMessages(type = 'ai', limit = 50, offset = 0) {
    return performChatGetMessages(this, type, limit, offset);
  }

  /**
   * Отправить сообщение AI (без streaming)
   * @param {string} content
   */
  async chatSendMessage(content) {
    return performChatSendMessage(this, content);
  }

  /**
   * Отправить сообщение AI с streaming
   * @param {string} content
   * @param {function(string)} onChunk - callback для каждого чанка
   * @param {object} opts - { onFirstChunk?: () => void, timeoutMs?: number, signal?: AbortSignal }
   */
  async chatSendMessageStream(content, onChunk, opts = {}) {
    return performChatSendMessageStream(this, content, onChunk, opts);
  }

  /**
   * Отправить сообщение администрации (из чата «От администрации»)
   * @param {string} content
   */
  async chatSendMessageToAdmin(content) {
    return performChatSendMessageToAdmin(this, content);
  }

  /**
   * Список диалогов: пользователи, которые писали мне через «Написать»
   */
  async chatGetDirectDialogs() {
    return performChatGetDirectDialogs(this);
  }

  /**
   * Сообщения между текущим пользователем и другим (диалог «Написать»)
   * @param {number} targetUserId ID собеседника
   * @param {number} limit
   * @param {number} offset
   */
  async chatGetDirectMessages(targetUserId, limit = 50, offset = 0) {
    return performChatGetDirectMessages(this, targetUserId, limit, offset);
  }

  /**
   * Отправить сообщение пользователю (от своего имени)
   * @param {number} targetUserId ID получателя
   * @param {string} content Текст сообщения
   */
  async chatSendMessageToUser(targetUserId, content) {
    return performChatSendMessageToUser(this, targetUserId, content);
  }

  /**
   * Очистить direct-диалог с пользователем
   * @param {number} targetUserId ID собеседника
   */
  async chatClearDirectDialog(targetUserId) {
    return performChatClearDirectDialog(this, targetUserId);
  }

  /**
   * Отметить сообщения как прочитанные
   * @param {number} conversationId
   */
  async chatMarkRead(conversationId) {
    return performChatMarkRead(this, conversationId);
  }

  /**
   * Очистить чат с AI
   */
  async chatClearAi() {
    return performChatClearAi(this);
  }

  /**
   * Отметить все сообщения во всех чатах как прочитанные
   */
  async chatMarkAllRead() {
    return performChatMarkAllRead(this);
  }

  /**
   * Админ: отметить все сообщения от пользователей как прочитанные
   */
  async chatAdminMarkAllRead() {
    return performChatAdminMarkAllRead(this);
  }

  /**
   * Админ: отправить сообщение пользователю
   * @param {number} userId - ID пользователя
   * @param {string} content - текст сообщения
   */
  async chatAdminSendMessage(userId, content) {
    return performChatAdminSendMessage(this, userId, content);
  }

  /**
   * Админ: список пользователей, которые писали в admin-чат
   */
  async getAdminChatUsers() {
    return performGetAdminChatUsers(this);
  }

  /**
   * Админ: получить сообщения пользователя (admin-чат)
   * @param {number} userId - ID пользователя
   * @param {number} limit
   * @param {number} offset
   */
  async chatAdminGetMessages(userId, limit = 50, offset = 0) {
    return performChatAdminGetMessages(this, userId, limit, offset);
  }

  /**
   * Админ: отметить диалог с пользователем как прочитанный (при открытии чата с ним)
   * @param {number} userId - ID пользователя
   */
  async chatAdminMarkConversationRead(userId) {
    return performChatAdminMarkConversationRead(this, userId);
  }

  /**
   * Добавить сообщение от AI пользователю (досыл, напоминание). Только для админа.
   * @param {number} userId - ID пользователя
   * @param {string} content - текст сообщения
   */
  async chatAddAIMessage(userId, content) {
    return performChatAddAIMessage(this, userId, content);
  }

  /**
   * Админ: непрочитанные сообщения от пользователей (для уведомлений)
   * @param {number} limit
   */
  async chatAdminGetUnreadNotifications(limit = 10) {
    return performChatAdminGetUnreadNotifications(this, limit);
  }

  /**
   * Админ: массовая рассылка сообщения
   * @param {string} content - текст сообщения
   * @param {number[]} [userIds] - опционально; если не указан — всем пользователям
   */
  async chatAdminBroadcast(content, userIds = null) {
    return performChatAdminBroadcast(this, content, userIds);
  }

  /**
   * Получить список закрытых уведомлений (синхронизация между устройствами)
   */
  async getNotificationsDismissed() {
    return performGetNotificationsDismissed(this);
  }

  /**
   * Закрыть уведомление
   * @param {string} notificationId - например "chat_123", "workout_2025-02-07"
   */
  async dismissNotification(notificationId) {
    return performDismissNotification(this, notificationId);
  }

  // ==================== Coach / Trainers ====================

  async listCoaches(params = {}) {
    return performListCoaches(this, params);
  }

  async requestCoach(coachId, message = '') {
    return performRequestCoach(this, coachId, message);
  }

  async getCoachRequests(params = {}) {
    return performGetCoachRequests(this, params);
  }

  async acceptCoachRequest(requestId) {
    return performAcceptCoachRequest(this, requestId);
  }

  async rejectCoachRequest(requestId) {
    return performRejectCoachRequest(this, requestId);
  }

  async getMyCoaches() {
    return performGetMyCoaches(this);
  }

  async removeCoach({ coachId, athleteId } = {}) {
    return performRemoveCoach(this, { coachId, athleteId });
  }

  async applyCoach(data) {
    return performApplyCoach(this, data);
  }

  async getCoachAthletes() {
    return performGetCoachAthletes(this);
  }

  async getCoachPricing(coachId = null) {
    return performGetCoachPricing(this, coachId);
  }

  async updateCoachPricing(pricing, pricesOnRequest = false) {
    return performUpdateCoachPricing(this, pricing, pricesOnRequest);
  }

  // Группы атлетов
  async getCoachGroups() {
    return performGetCoachGroups(this);
  }

  async saveCoachGroup(data) {
    return performSaveCoachGroup(this, data);
  }

  async deleteCoachGroup(groupId) {
    return performDeleteCoachGroup(this, groupId);
  }

  async getGroupMembers(groupId) {
    return performGetGroupMembers(this, groupId);
  }

  async updateGroupMembers(groupId, userIds) {
    return performUpdateGroupMembers(this, groupId, userIds);
  }

  async getAthleteGroups(userId) {
    return performGetAthleteGroups(this, userId);
  }

  async getCoachApplications(params = {}) {
    return performGetCoachApplications(this, params);
  }

  async approveCoachApplication(applicationId) {
    return performApproveCoachApplication(this, applicationId);
  }

  async rejectCoachApplication(applicationId) {
    return performRejectCoachApplication(this, applicationId);
  }
}

export default ApiClient;
export { ApiError };
