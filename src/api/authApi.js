import { isNativeCapacitor } from '../services/TokenStorageService';
import { ApiError, buildApiError, extractRetryAfter } from './apiError';

const AUTH_REQUEST_TIMEOUT_MS = 15000;
const LOGOUT_TIMEOUT_MS = 5000;
const DEVICE_ID_TIMEOUT_MS = 3000;
const TOKEN_PERSIST_TIMEOUT_MS = 2500;

async function fetchWithTimeout(url, options, timeoutMs = AUTH_REQUEST_TIMEOUT_MS) {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

  try {
    return await fetch(url, {
      ...options,
      signal: controller.signal,
    });
  } finally {
    clearTimeout(timeoutId);
  }
}

async function fetchJson(url, options, timeoutMs = AUTH_REQUEST_TIMEOUT_MS) {
  const response = await fetchWithTimeout(url, options, timeoutMs);
  const data = await response.json().catch(() => ({}));
  return { response, data };
}

async function applySessionTokens(client, accessToken, refreshToken) {
  if (!accessToken || !refreshToken) {
    return;
  }

  const persist = client.setToken(accessToken, refreshToken).catch((error) => {
    if (process.env.NODE_ENV !== 'production') {
      console.warn('[authApi] setToken failed during auth flow:', error?.message || error);
    }
  });

  // In-memory tokens are assigned synchronously in ApiClient.setToken(),
  // so native storage persistence should not be allowed to block navigation.
  await Promise.race([
    persist,
    new Promise((resolve) => setTimeout(resolve, TOKEN_PERSIST_TIMEOUT_MS)),
  ]);
}

function getAuthWrapperUrl(baseUrl, action) {
  const urlParams = new URLSearchParams({ action });
  return `${baseUrl}/api_wrapper.php?${urlParams.toString()}`;
}

async function login(client, username, password, useJwt = false) {
  if (useJwt || (typeof window !== 'undefined' && window.Capacitor)) {
    return loginWithJwt(client, username, password);
  }

  const url = getAuthWrapperUrl(client.baseUrl, 'login');

  try {
    const { response, data } = await fetchJson(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ username, password, use_jwt: false }),
    });

    if (!response.ok) {
      throw new ApiError({
        code: 'LOGIN_FAILED',
        message: data.error || data.message || 'Неверный логин или пароль',
        status: response.status,
        retry_after: extractRetryAfter(response, data, data.error || data.message || 'Неверный логин или пароль'),
      });
    }

    if (data.success && data.data) {
      if (!isNativeCapacitor() && typeof localStorage !== 'undefined') {
        localStorage.removeItem('auth_token');
        localStorage.removeItem('refresh_token');
        client.token = null;
        client.refreshToken = null;
      }

      const userData = await client.getCurrentUser();
      return {
        success: true,
        user: userData || {
          id: data.data.user_id,
          username: data.data.username,
          authenticated: true,
        },
      };
    }

    throw new ApiError({
      code: 'LOGIN_FAILED',
      message: data.error || data.message || 'Неверный логин или пароль',
      status: response.status,
    });
  } catch (error) {
    if (error instanceof ApiError) {
      throw error;
    }
    throw new ApiError({ code: 'LOGIN_FAILED', message: error.message });
  }
}

async function loginWithJwt(client, username, password) {
  const url = getAuthWrapperUrl(client.baseUrl, 'login');

  let deviceId = null;
  try {
    deviceId = await Promise.race([
      client.getOrCreateDeviceId(),
      new Promise((resolve) => setTimeout(() => resolve(null), DEVICE_ID_TIMEOUT_MS)),
    ]);
  } catch {
    deviceId = null;
  }

  const body = { username, password, use_jwt: true };
  if (deviceId) body.device_id = deviceId;

  try {
    const { response, data } = await fetchJson(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });

    if (!response.ok) {
      throw new ApiError({
        code: 'LOGIN_FAILED',
        message: data.error || data.message || 'Неверный логин или пароль',
        status: response.status,
        retry_after: extractRetryAfter(response, data, data.error || data.message || 'Неверный логин или пароль'),
      });
    }

    if (data.success && data.data) {
      const { access_token, refresh_token, user_id, username: usernameFromResponse } = data.data;
      await applySessionTokens(client, access_token, refresh_token);

      return {
        success: true,
        user: {
          id: user_id,
          user_id,
          username: usernameFromResponse,
          authenticated: true,
        },
        access_token,
        refresh_token,
      };
    }

    throw new ApiError({
      code: 'LOGIN_FAILED',
      message: data.error || data.message || 'Неверный логин или пароль',
      status: response.status,
    });
  } catch (error) {
    if (error instanceof ApiError) {
      throw error;
    }
    throw new ApiError({ code: 'LOGIN_FAILED', message: error.message });
  }
}

async function logout(client) {
  try {
    const refreshToken = await client.getRefreshToken();

    if (refreshToken) {
      await fetchWithTimeout(getAuthWrapperUrl(client.baseUrl, 'logout'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refresh_token: refreshToken }),
      }, LOGOUT_TIMEOUT_MS).catch(() => {});
    } else {
      await fetchWithTimeout(`${client.baseUrl}/api_wrapper.php?action=logout`, {
        method: 'POST',
        credentials: 'include',
      }, LOGOUT_TIMEOUT_MS).catch(() => {});
    }
  } finally {
    await client.setToken(null, null);
  }
}

async function requestResetPassword(client, email) {
  const { response, data } = await fetchJson(`${client.baseUrl}/api_wrapper.php?action=request_password_reset`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({ email: (email || '').trim() }),
  });

  if (!response.ok) {
    throw buildApiError({
      response,
      data,
      code: response.status === 429 ? 'RATE_LIMITED' : 'RESET_FAILED',
      message: 'Не удалось запросить сброс пароля',
    });
  }

  return {
    success: data.success,
    sent: data.data?.sent ?? false,
    message: data.data?.message ?? null,
    email: data.data?.email ?? null,
  };
}

async function confirmResetPassword(client, token, newPassword) {
  const { response, data } = await fetchJson(`${client.baseUrl}/api_wrapper.php?action=confirm_password_reset`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({
      token: (token || '').trim(),
      new_password: (newPassword || '').trim(),
    }),
  });

  if (!response.ok) {
    throw buildApiError({
      response,
      data,
      code: response.status === 429 ? 'RATE_LIMITED' : 'RESET_FAILED',
      message: 'Не удалось сменить пароль',
    });
  }

  return { success: data.success };
}

async function sendVerificationCode(client, email) {
  const { response, data } = await fetchJson(`${client.baseUrl}/register_api.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({ action: 'send_verification_code', email: (email || '').trim() }),
  });

  if (!response.ok || !data.success) {
    throw buildApiError({
      response,
      data,
      code: response.status === 429 ? 'RATE_LIMITED' : 'VERIFICATION_SEND_FAILED',
      message: 'Не удалось отправить код',
    });
  }

  return { success: true, message: data.message };
}

async function registerMinimal(client, { username, email, password, verification_code }) {
  const nativeApp = isNativeCapacitor();
  let deviceId = null;
  if (nativeApp) {
    try {
      deviceId = await Promise.race([
        client.getOrCreateDeviceId(),
        new Promise((resolve) => setTimeout(() => resolve(null), DEVICE_ID_TIMEOUT_MS)),
      ]);
    } catch {
      deviceId = null;
    }
  }

  const payload = {
    username,
    email,
    password,
    register_minimal: true,
    verification_code: (verification_code || '').trim(),
    use_jwt: nativeApp,
  };
  if (deviceId) payload.device_id = deviceId;

  try {
    const fetchOptions = {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    };
    if (!nativeApp) {
      fetchOptions.credentials = 'include';
    }

    const { response, data } = await fetchJson(`${client.baseUrl}/register_api.php`, fetchOptions);
    const contentType = response.headers.get('content-type') || '';

    if (contentType && contentType.includes('application/json')) {
      if (data.success) {
        const registeredUser = data.user || {
          username,
          email,
          authenticated: true,
        };

        if (data.access_token && data.refresh_token) {
          await applySessionTokens(client, data.access_token, data.refresh_token);
          return {
            success: true,
            user: {
              ...registeredUser,
              authenticated: true,
            },
            access_token: data.access_token,
            refresh_token: data.refresh_token,
            plan_message: data.plan_message ?? null,
          };
        }

        if (nativeApp) {
          const loginResult = await loginWithJwt(client, username, password);
          return {
            success: true,
            user: {
              ...registeredUser,
              ...(loginResult.user || {}),
              authenticated: true,
            },
            access_token: loginResult.access_token,
            refresh_token: loginResult.refresh_token,
            plan_message: data.plan_message ?? null,
          };
        }

        let user = null;
        try {
          user = await client.getCurrentUser();
        } catch (_) {
          user = null;
        }

        return {
          success: true,
          user: user ?? { ...registeredUser, authenticated: true },
          plan_message: data.plan_message ?? null,
        };
      }

      throw new ApiError({
        code: response.status === 429 ? 'RATE_LIMITED' : 'REGISTRATION_FAILED',
        message: data.error || 'Ошибка регистрации',
        attempts_left: data.attempts_left,
        status: response.status,
        retry_after: extractRetryAfter(response, data, data.error || 'Ошибка регистрации'),
      });
    }

    throw new ApiError({ message: 'Registration failed: unexpected response type' });
  } catch (error) {
    if (error instanceof ApiError) throw error;
    throw new ApiError({ code: 'REGISTRATION_FAILED', message: error.message });
  }
}

async function register(client, userData) {
  try {
    const response = await fetch(`${client.baseUrl}/register_api.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify(userData),
    });
    const contentType = response.headers.get('content-type');

    if (contentType && contentType.includes('application/json')) {
      const data = await response.json();
      if (data.success) {
        const user = await client.getCurrentUser();
        return {
          success: true,
          user: user ?? data.user,
          plan_message: data.plan_message ?? null,
        };
      }

      throw new ApiError({
        code: 'REGISTRATION_FAILED',
        message: data.error || 'Ошибка регистрации',
      });
    }

    throw new ApiError({ message: `Registration failed: ${await response.text()}` });
  } catch (error) {
    if (error instanceof ApiError) {
      throw error;
    }
    throw new ApiError({ code: 'REGISTRATION_FAILED', message: error.message });
  }
}

async function completeSpecialization(client, payload) {
  try {
    const nativeApp = isNativeCapacitor();
    const headers = { 'Content-Type': 'application/json' };
    const requestOptions = {
      method: 'POST',
      headers,
      body: JSON.stringify(payload),
    };

    if (!nativeApp) {
      requestOptions.credentials = 'include';
    } else {
      const accessToken = await client.getToken().catch(() => null);
      if (accessToken) {
        headers.Authorization = `Bearer ${accessToken}`;
      }
    }

    const response = await fetch(`${client.baseUrl}/complete_specialization_api.php`, requestOptions);
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

async function validateField(client, field, value) {
  const validateUrl = `${client.baseUrl}/register_api.php?action=validate_field&field=${encodeURIComponent(field)}&value=${encodeURIComponent(value)}`;

  try {
    const response = await fetch(validateUrl, {
      method: 'GET',
      credentials: 'include',
    });

    return await response.json();
  } catch {
    return { valid: false, message: 'Ошибка валидации' };
  }
}

export {
  login,
  loginWithJwt,
  logout,
  requestResetPassword,
  confirmResetPassword,
  sendVerificationCode,
  registerMinimal,
  register,
  completeSpecialization,
  validateField,
};
