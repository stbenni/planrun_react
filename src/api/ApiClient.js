/**
 * Универсальный API клиент для React (веб) и React Native (мобильное)
 * Один код для всех платформ
 */

class ApiError extends Error {
  constructor({ code, message, attempts_left }) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.message = message;
    this.attempts_left = attempts_left;
  }
}

class ApiClient {
  constructor(baseUrl = null) {
    const origin = typeof window !== 'undefined' ? window.location.origin : '';
    const envBase = typeof import.meta !== 'undefined' && import.meta.env && import.meta.env.VITE_API_BASE_URL;
    // Явный URL — если передан baseUrl или VITE_API_BASE_URL (для cross-origin)
    const explicit = baseUrl ?? (envBase !== undefined && envBase !== '' ? envBase : null);

    if (explicit) {
      this.baseUrl = explicit;
    } else {
      if (typeof window !== 'undefined' && window.Capacitor) {
        this.baseUrl = (origin || '') + '/api';
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

  async setToken(token, refreshToken = null) {
    this.token = token;
    if (refreshToken) {
      this.refreshToken = refreshToken;
    }
    
    if (typeof localStorage !== 'undefined') {
      // Для веба используем localStorage (синхронно)
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

  async getToken() {
    if (this.token) {
      return this.token;
    }
    
    if (typeof localStorage !== 'undefined') {
      // Для веба (синхронно)
      const token = localStorage.getItem('auth_token');
      const refreshToken = localStorage.getItem('refresh_token');
      if (token) {
        this.token = token;
      }
      if (refreshToken) {
        this.refreshToken = refreshToken;
      }
      return token;
    } else {
      // Для React Native (асинхронно)
      // AsyncStorage должен быть импортирован в React Native версии
      try {
        if (typeof AsyncStorage !== 'undefined') {
          const token = await AsyncStorage.getItem('auth_token');
          const refreshToken = await AsyncStorage.getItem('refresh_token');
          if (token) {
            this.token = token;
          }
          if (refreshToken) {
            this.refreshToken = refreshToken;
          }
          return token;
        }
      } catch (error) {
        console.error('Error getting token:', error);
        return null;
      }
    }
    
    return null;
  }

  async getRefreshToken() {
    if (this.refreshToken) {
      return this.refreshToken;
    }
    
    if (typeof localStorage !== 'undefined') {
      return localStorage.getItem('refresh_token');
    } else {
      try {
        if (typeof AsyncStorage !== 'undefined') {
          return await AsyncStorage.getItem('refresh_token');
        }
      } catch (error) {
        console.error('Error getting refresh token:', error);
        return null;
      }
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
    this.refreshPromise = (async () => {
      try {
        const refreshToken = await this.getRefreshToken();
        if (!refreshToken) {
          throw new Error('No refresh token available');
        }

        const urlParams = new URLSearchParams({ action: 'refresh_token' });
        // Используем api_wrapper.php который проксирует к api_v2.php
        const url = `${this.baseUrl}/api_wrapper.php?${urlParams.toString()}`;

        const response = await fetch(url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({ refresh_token: refreshToken }),
        });

        if (!response.ok) {
          throw new Error('Failed to refresh token');
        }

        const data = await response.json();
        if (data.success && data.data) {
          const { access_token, refresh_token } = data.data;
          await this.setToken(access_token, refresh_token);
          return access_token;
        } else {
          throw new Error(data.error || 'Failed to refresh token');
        }
      } catch (error) {
        // Очищаем токены при ошибке обновления
        await this.setToken(null, null);
        if (this.onTokenExpired) {
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
    if (action === 'chat_get_messages') {
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

    // Добавляем токен авторизации если есть
    if (this.token) {
      headers['Authorization'] = `Bearer ${this.token}`;
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
      const response = await fetch(finalUrl, options);
      
      // Обработка ошибок авторизации
      if (response.status === 401) {
        // Пытаемся обновить токен если есть refresh token
        const refreshToken = await this.getRefreshToken();
        if (refreshToken && !this.isRefreshing) {
          try {
            const newToken = await this.refreshAccessToken();
            // Повторяем запрос с новым токеном
            headers['Authorization'] = `Bearer ${newToken}`;
            const retryResponse = await fetch(url, { ...options, headers });
            if (retryResponse.ok) {
              const retryData = await retryResponse.json();
              return retryData.data || retryData;
            }
          } catch (refreshError) {
            // Не удалось обновить токен
            console.error('Failed to refresh token:', refreshError);
          }
        }
        
        if (this.onTokenExpired) {
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
      throw new ApiError({
        code: 'NETWORK_ERROR',
        message: error.message || 'Network error'
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
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        credentials: 'include', // ВАЖНО: для передачи cookies (PHP сессии)
        body: JSON.stringify({
          username,
          password,
          use_jwt: false // Используем сессии для веба
        }),
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new ApiError({
          code: 'LOGIN_FAILED',
          message: errorData.error || errorData.message || 'Неверный логин или пароль'
        });
      }

      const data = await response.json();
      if (data.success && data.data) {
        // Сессия установлена через cookies, получаем данные пользователя
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

    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          username,
          password,
          use_jwt: true
        }),
      });

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
            username: usernameFromResponse
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
    try {
      const refreshToken = await this.getRefreshToken();
      
      // Если есть refresh token, используем JWT logout
      if (refreshToken) {
        const urlParams = new URLSearchParams({ action: 'logout' });
        const url = `${this.baseUrl}/api_wrapper.php?${urlParams.toString()}`;
        
        await fetch(url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({ refresh_token: refreshToken }),
        });
      } else {
        // Для веба используем сессионный logout через api_wrapper.php
        const logoutUrl = `${this.baseUrl}/api_wrapper.php?action=logout`;
        
        await fetch(logoutUrl, {
          method: 'POST',
          credentials: 'include', // ВАЖНО: для передачи cookies (PHP сессии)
        });
      }
    } finally {
      // Очищаем токены из localStorage
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
   */
  async getCurrentUser() {
    try {
      // Сначала проверяем авторизацию через простой endpoint
      const authCheck = await this.request('check_auth', {}, 'GET');
      
      // Проверяем структуру ответа
      // BaseController возвращает {success: true, data: {...}}
      const data = authCheck?.data || authCheck;
      const isAuthenticated = data?.authenticated;
      const userId = data?.user_id;
      const username = data?.username;
      const name = data?.name ?? null;
      const avatarPath = data?.avatar_path ?? null;
      const role = data?.role ?? 'user';
      const onboardingCompleted = data?.onboarding_completed !== undefined ? !!data.onboarding_completed : false;
      const timezone = data?.timezone ?? null;

      if (isAuthenticated) {
        const baseUser = {
          authenticated: true,
          user_id: userId,
          username: username,
          role,
          onboarding_completed: onboardingCompleted,
          ...(name != null && { name }),
          ...(avatarPath != null && avatarPath !== '' && { avatar_path: avatarPath }),
          ...(timezone != null && timezone !== '' && { timezone })
        };
        try {
          const plan = await this.getPlan();
          return { ...baseUser, plan };
        } catch (error) {
          return { ...baseUser, plan: null };
        }
      }
      
      // Пользователь не авторизован
      return null;
    } catch (error) {
      // Если ошибка парсинга или редиректа, логируем для отладки
      if (error.code === 'PARSE_ERROR' || error.code === 'HTML_RESPONSE' || error.code === 'REDIRECT_ERROR') {
        console.error('Error checking auth (server response issue):', error.message);
        // Возвращаем null, чтобы не блокировать приложение
        return null;
      }
      
      // Если ошибка авторизации (403), возвращаем null
      if (error.code === 'UNAUTHORIZED' || error.code === 'NOT_AUTHENTICATED' || 
          error.code === 'FORBIDDEN' || error.message?.includes('авторизац') ||
          error.message?.includes('Требуется авторизация')) {
        return null;
      }
      // Для других ошибок тоже возвращаем null (пользователь не авторизован)
      console.error('Error checking auth:', error);
      return null;
    }
  }

  // ========== ПЛАНЫ ТРЕНИРОВОК ==========

  async getPlan(userId = null) {
    const params = userId ? { user_id: userId } : {};
    return this.request('load', params, 'GET');
  }

  async savePlan(planData) {
    return this.request('save', { plan: JSON.stringify(planData) }, 'POST');
  }

  // ========== ТРЕНИРОВКИ ==========

  async getDay(date) {
    return this.request('get_day', { date }, 'GET');
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

  async getResult(date) {
    return this.request('get_result', { date }, 'GET');
  }

  async getAllResults() {
    return this.request('get_all_results', {}, 'GET');
  }

  async reset(date) {
    return this.request('reset', { date }, 'POST');
  }

  // ========== СТАТИСТИКА ==========

  async getStats() {
    return this.request('stats', {}, 'GET');
  }

  async getAllWorkoutsSummary() {
    return this.request('get_all_workouts_summary', {}, 'GET');
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
   * @param {object} opts - { onFirstChunk?: () => void, timeoutMs?: number }
   */
  async chatSendMessageStream(content, onChunk, opts = {}) {
    const { onFirstChunk, timeoutMs = 180000 } = opts;
    const urlParams = new URLSearchParams({ action: 'chat_send_message_stream' });
    const url = `${this.baseUrl}/api_wrapper.php?${urlParams.toString()}`;

    const token = await this.getToken();
    const headers = { 'Content-Type': 'application/json' };
    if (token) headers['Authorization'] = `Bearer ${token}`;

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

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
            if (obj.chunk && typeof onChunk === 'function') {
              if (!firstChunkFired && typeof onFirstChunk === 'function') {
                firstChunkFired = true;
                onFirstChunk();
              }
              onChunk(obj.chunk);
            }
          } catch (e) {
            if (e instanceof ApiError) throw e;
          }
        }
      }
      if (buffer.trim()) {
        const obj = JSON.parse(buffer.trim());
        if (obj.error) throw new ApiError({ code: 'CHAT_FAILED', message: obj.error });
        if (obj.chunk && typeof onChunk === 'function') {
          if (!firstChunkFired && typeof onFirstChunk === 'function') onFirstChunk();
          onChunk(obj.chunk);
        }
      }
    } finally {
      reader.releaseLock?.();
    }
  }

  /**
   * Отправить сообщение администрации (из чата «От администрации»)
   * @param {string} content
   */
  async chatSendMessageToAdmin(content) {
    return this.request('chat_send_message_to_admin', { content: (content || '').trim() }, 'POST');
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
