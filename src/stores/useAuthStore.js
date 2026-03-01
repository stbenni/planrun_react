/**
 * Store для управления авторизацией
 * Использует Zustand для глобального состояния
 */

import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import ApiClient from '../api/ApiClient';
import BiometricService from '../services/BiometricService';
import PinAuthService from '../services/PinAuthService';
import CredentialBackupService from '../services/CredentialBackupService';
import TokenStorageService, { isNativeCapacitor } from '../services/TokenStorageService';

const useAuthStore = create(
  persist(
    (set, get) => ({
      // Состояние
      user: null,
      api: null,
      loading: true,
      isAuthenticated: false,
      showOnboardingModal: false,
      /** Сообщение о генерации плана после специализации (показывается на дашборде) */
      planGenerationMessage: null,
      /** Открыто ли боковое меню профиля (мобильное приложение) */
      drawerOpen: false,
      setDrawerOpen: (open) => set({ drawerOpen: typeof open === 'function' ? open(get().drawerOpen) : open }),
      /** Требуется разблокировка (PIN или биометрия) перед показом приложения */
      isLocked: false,
      setLocked: (value) => set({ isLocked: value }),
      /** Время ухода в фон (для блокировки через 15 мин) */
      lastActiveAt: 0,
      /** Идёт разблокировка (pinLogin/biometricLogin) — onTokenExpired не должен вмешиваться */
      _unlocking: false,
      /** Идёт бесшовное восстановление по credentials — не допускаем параллельных попыток */
      _credentialRecoveryInProgress: false,
      /** Включена ли блокировка при уходе в фон (true только при PIN/биометрии) */
      _lockEnabled: false,

      // Инициализация
      initialize: async () => {
        const apiClient = new ApiClient();
        // Обработчик истечения токена: пытаемся восстановить сессию по credentials,
        // только если не удалось — разлогиниваем. Работает для всех native-пользователей,
        // включая тех, кто не настраивал PIN/биометрию.
        apiClient.onTokenExpired = async () => {
          if (get()._unlocking || get().isLocked) return;
          if (get()._credentialRecoveryInProgress) return;

          if (isNativeCapacitor()) {
            set({ _credentialRecoveryInProgress: true });
            try {
              const hasCredentials = await CredentialBackupService.hasCredentials();
              if (hasCredentials) {
                const result = await CredentialBackupService.recoverAndLoginBiometric(apiClient);
                if (result?.success) {
                  return;
                }
              }
            } catch (_) {
            } finally {
              set({ _credentialRecoveryInProgress: false });
            }
          }

          await get().logout(false);
        };
        // api в store всегда задаём, чтобы форма логина работала даже при ошибке getCurrentUser
        set({ api: apiClient });

        // Жёсткий лимит: максимум 3 сек до показа экрана (плагины/API могут зависать)
        const safetyTimeout = setTimeout(() => {
          if (get().loading) {
            console.warn('[Auth] Init timeout (3s) — showing login');
            set({ loading: false });
          }
        }, 3000);

        try {
          const isNative = isNativeCapacitor();

          if (isNative) {
            // Проверяем PIN/биометрию ДО токенов — lock screen должен показываться
            // даже если localStorage/SecureStorage очищены после обновления.
            // PinAuthService и CredentialBackupService хранят данные в Preferences,
            // которые переживают обновления. Recovery-цепочка при unlock восстановит сессию.
            const [pinEnabled, biometricEnabled] = await Promise.all([
              PinAuthService.isPinEnabled(),
              BiometricService.isBiometricEnabled()
            ]);

            // Синхронизируем токены из бэкапа в localStorage (если localStorage очищен)
            const hasLocal = typeof localStorage !== 'undefined' && !!localStorage.getItem('auth_token');
            if (!hasLocal) {
              try {
                const stored = await TokenStorageService.getTokens();
                if (stored?.accessToken && stored?.refreshToken && typeof localStorage !== 'undefined') {
                  localStorage.setItem('auth_token', stored.accessToken);
                  localStorage.setItem('refresh_token', stored.refreshToken);
                }
              } catch (_) {}
            }

            if (pinEnabled || biometricEnabled) {
              set({ isLocked: true, _lockEnabled: true, loading: false });
              get().setupBackgroundLock?.();
              clearTimeout(safetyTimeout);
              return;
            }

            // Нет PIN/биометрии и нет токенов — показываем логин
            if (!hasLocal && !localStorage?.getItem('auth_token')) {
              set({ loading: false });
              clearTimeout(safetyTimeout);
              return;
            }
          }

          // Проверяем авторизацию через PHP сессию (cookies) или JWT
          try {
            const userData = await apiClient.getCurrentUser();
            if (userData && userData.authenticated) {
              set({
                user: userData,
                isAuthenticated: true
              });
              if (isNative) {
                const [pinEnabled, biometricEnabled] = await Promise.all([
                  PinAuthService.isPinEnabled(),
                  BiometricService.isBiometricEnabled()
                ]);
                if (pinEnabled || biometricEnabled) {
                  set({ _lockEnabled: true });
                }
                get().setupBackgroundLock?.();
              }
              // План — в фоне, не блокируя показ UI (Dashboard подхватит из store)
              if (userData.onboarding_completed) {
                import('./usePlanStore').then(async (mod) => {
                  const planStore = mod.default.getState();
                  const status = await planStore.checkPlanStatus().catch(() => null);
                  if (status?.has_plan) planStore.loadPlan();
                  else planStore.setPlanStatusChecked(false);
                }).catch(() => {});
              }
            }
          } catch (error) {
            console.log('User not authenticated:', error.message);
          }
        } catch (error) {
          console.error('Error initializing app:', error);
        } finally {
          clearTimeout(safetyTimeout);
          set({ loading: false });
        }
      },

      /** Настроить foreground-refresh и блокировку при уходе в фон (Capacitor, все пользователи).
       *  Блокировка срабатывает только при _lockEnabled (PIN/биометрия). */
      setupBackgroundLock: () => {
        if (get()._backgroundLockSetup) return;
        set({ _backgroundLockSetup: true, lastActiveAt: Date.now() });
        const LOCK_AFTER_MS = 15 * 60 * 1000;
        const checkAndLockSync = () => {
          const { isLocked, lastActiveAt, _lockEnabled } = get();
          if (!_lockEnabled || isLocked) return;
          if (lastActiveAt && Date.now() - lastActiveAt > LOCK_AFTER_MS) {
            set({ isLocked: true });
          }
        };
        const onBackground = () => set({ lastActiveAt: Date.now() });
        const onForeground = () => {
          checkAndLockSync();
          const { isAuthenticated, isLocked, api } = get();
          if (!api) return;
          if (!isLocked && isAuthenticated) {
            api.getCurrentUser().catch(() => {});
          }
        };
        if (typeof document !== 'undefined') {
          document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') onBackground();
            else onForeground();
          });
        }
        if (isNativeCapacitor()) {
          import('@capacitor/app').then(({ App }) => {
            App.addListener('appStateChange', ({ isActive }) => {
              if (!isActive) onBackground();
              else onForeground();
            });
          }).catch(() => {});
        }
      },

      /** Разблокировка после ввода PIN или биометрии */
      unlock: async (tokens) => {
        const { api } = get();
        if (!api || !tokens?.accessToken || !tokens?.refreshToken) {
          throw new Error('Invalid unlock');
        }
        await api.setToken(tokens.accessToken, tokens.refreshToken);
        const userData = await api.getCurrentUser();
        if (userData?.authenticated) {
          set({ user: userData, isAuthenticated: true, isLocked: false });
          // План и push — в фоне
          if (userData.onboarding_completed) {
            import('./usePlanStore').then(async (mod) => {
              const planStore = mod.default.getState();
              const status = await planStore.checkPlanStatus().catch(() => null);
              if (status?.has_plan) planStore.loadPlan();
              else planStore.setPlanStatusChecked(false);
            }).catch(() => {});
          }
          if (isNativeCapacitor()) {
            import('../services/PushService').then(({ registerPushNotifications }) => {
              registerPushNotifications(get().api).catch(() => {});
            });
          }
          return { success: true };
        }
        throw new Error('Сессия недействительна');
      },

      /**
       * Общая логика разблокировки: setToken → getCurrentUser → recovery → loadPlan → push.
       * @param {{ accessToken: string, refreshToken: string }} tokens
       * @param {(api: ApiClient) => Promise<{ success: boolean }>} recoveryFn — fallback при протухшей сессии
       */
      _completeUnlock: async (tokens, recoveryFn) => {
        const { api } = get();
        if (!api) throw new Error('API client not initialized');

        if (tokens?.accessToken && tokens?.refreshToken) {
          await api.setToken(tokens.accessToken, tokens.refreshToken);
        }

        let userData;
        try {
          userData = await api.getCurrentUser({ throwOnNetworkError: true });
        } catch (authErr) {
          const isNetworkErr = authErr?.code === 'NETWORK_ERROR' || authErr?.code === 'TIMEOUT' ||
            /нет соединения|network|fetch/i.test(authErr?.message || '');
          if (isNetworkErr) throw authErr;
          userData = null;
        }

        if (!userData?.authenticated && recoveryFn) {
          try {
            const recover = await recoveryFn(api);
            if (recover?.success) {
              userData = await api.getCurrentUser({ throwOnNetworkError: true });
            }
          } catch (recErr) {
            const isNetworkErr = recErr?.code === 'NETWORK_ERROR' || recErr?.code === 'TIMEOUT' ||
              /нет соединения|network|fetch/i.test(recErr?.message || '');
            if (isNetworkErr) throw recErr;
          }
        }

        if (userData?.authenticated) {
          set({ user: userData, isAuthenticated: true, isLocked: false });
          if (userData.onboarding_completed) {
            import('./usePlanStore').then(async (mod) => {
              const planStore = mod.default.getState();
              const status = await planStore.checkPlanStatus().catch(() => null);
              if (status?.has_plan) planStore.loadPlan();
              else planStore.setPlanStatusChecked(false);
            }).catch(() => {});
          }
          if (isNativeCapacitor()) {
            import('../services/PushService').then(({ registerPushNotifications }) => {
              registerPushNotifications(get().api).catch(() => {});
            });
          }
          return { success: true };
        }

        return { success: false, error: 'Сессия истекла. Войдите по паролю для обновления.' };
      },

      // Вход
      login: async (username, password, useJwt = false) => {
        const { api } = get();
        if (!api) {
          throw new Error('API client not initialized');
        }

        try {
          const result = await api.login(username, password, useJwt);
          
          if (result.success) {
            const user = result.user || { authenticated: true };
            set({ user, isAuthenticated: true });
            const fullUser = await get().api.getCurrentUser().catch(() => null);
            if (fullUser?.authenticated) {
              set({ user: fullUser });
            }
            // План и push — в фоне, не блокируя навигацию на Dashboard
            const bgUser = fullUser ?? user;
            if (bgUser?.onboarding_completed) {
              import('./usePlanStore').then(async (mod) => {
                const planStore = mod.default.getState();
                const status = await planStore.checkPlanStatus().catch(() => null);
                if (status?.has_plan) planStore.loadPlan();
                else planStore.setPlanStatusChecked(false);
              }).catch(() => {});
            }
            if (isNativeCapacitor()) {
              get().setupBackgroundLock?.();
              import('../services/PushService').then(({ registerPushNotifications }) => {
                registerPushNotifications(get().api).catch(() => {});
              });
            }
            return {
              success: true,
              access_token: result.access_token,
              refresh_token: result.refresh_token
            };
          }
          
          return { success: false, error: 'Login failed' };
        } catch (error) {
          return { success: false, error: error.message || 'Login failed' };
        }
      },

      // Выход
      // clearStoredCredentials: true — явный выход (очищаем биометрию и PIN)
      // clearStoredCredentials: false — сессия истекла (оставляем биометрию и PIN для входа)
      logout: async (clearStoredCredentials = true) => {
        const { api } = get();
        
        try {
          if (clearStoredCredentials && isNativeCapacitor()) {
            const { unregisterPushNotifications } = await import('../services/PushService');
            unregisterPushNotifications(api).catch(() => {});
          }
          if (api) {
            await api.logout();
          }
          
          if (clearStoredCredentials) {
            await BiometricService.clearTokens();
            await PinAuthService.clearPin();
            if (isNativeCapacitor()) {
              await CredentialBackupService.clearCredentials();
            }
          }
          
          set({ 
            user: null, 
            isAuthenticated: false,
            isLocked: false
          });
          (await import('./usePlanStore')).default.getState().clearPlan();
        } catch (error) {
          console.error('Logout error:', error);
          set({ 
            user: null, 
            isAuthenticated: false,
            isLocked: false
          });
          (await import('./usePlanStore')).default.getState().clearPlan();
        }
      },

      // Вход по PIN-коду
      // Приложение может быть выгружено долгое время — TokenStorageService обновляется при каждом refresh,
      // PinAuthService хранит снимок на момент настройки PIN. Приоритет: TokenStorageService (свежие токены).
      pinLogin: async (pin) => {
        set({ _unlocking: true });
        try {
          const result = await PinAuthService.verifyAndGetTokens(pin);
          if (!result.success || !result.tokens) {
            return { success: false, error: result.error || 'Неверный PIN' };
          }

          const { api } = get();
          if (!api) throw new Error('API client not initialized');

          let tokens = result.tokens;
          if (isNativeCapacitor()) {
            try {
              const stored = await TokenStorageService.getTokens();
              if (stored?.accessToken && stored?.refreshToken) {
                tokens = { accessToken: stored.accessToken, refreshToken: stored.refreshToken };
              }
            } catch (e) {
              // fallback на токены из PinAuthService
            }
          }

          const unlockResult = await get()._completeUnlock(tokens, async (api) => {
            if (await CredentialBackupService.hasCredentials()) {
              return CredentialBackupService.recoverAndLogin(pin, api);
            }
            return { success: false };
          });

          if (unlockResult.success) {
            const current = api.getTokens();
            if (current.accessToken && current.refreshToken) {
              PinAuthService.setPinAndSaveTokens(pin, current.accessToken, current.refreshToken).catch(() => {});
            }
          }

          return unlockResult;
        } catch (error) {
          const isNetworkError = error?.code === 'NETWORK_ERROR' || /нет соединения|network|fetch/i.test(error?.message || '');
          return {
            success: false,
            error: isNetworkError ? 'Нет соединения. Проверьте интернет и нажмите «Повторить».' : (error.message || 'Ошибка входа по PIN'),
            isNetworkError
          };
        } finally {
          set({ _unlocking: false });
        }
      },

      biometricLogin: async () => {
        set({ _unlocking: true });
        try {
          const result = await BiometricService.authenticateAndGetTokens(
            'Используйте биометрию для входа в PlanRun'
          );

          if (!result.success) {
            return {
              success: false,
              error: result.error || 'Биометрическая аутентификация не прошла'
            };
          }

          const recoveryFn = async (api) => {
            if (await CredentialBackupService.hasCredentials()) {
              return CredentialBackupService.recoverAndLoginBiometric(api);
            }
            return { success: false };
          };

          return await get()._completeUnlock(result.tokens, recoveryFn);
        } catch (error) {
          const isNetworkError = error?.code === 'NETWORK_ERROR' || /нет соединения|network|fetch/i.test(error?.message || '');
          return {
            success: false,
            error: isNetworkError
              ? 'Нет соединения. Проверьте интернет и нажмите «Повторить».'
              : (error.message || 'Произошла ошибка при биометрической аутентификации'),
            isNetworkError
          };
        } finally {
          set({ _unlocking: false });
        }
      },

      setShowOnboardingModal: (value) => set({ showOnboardingModal: value }),
      setPlanGenerationMessage: (message) => set({ planGenerationMessage: message }),

      // Обновление данных пользователя
      updateUser: (userData) => {
        // Если userData содержит authenticated: true, устанавливаем isAuthenticated
        const isAuth = userData?.authenticated === true || userData === true;
        set({ 
          user: userData,
          isAuthenticated: isAuth || get().isAuthenticated
        });
      },

      // Проверка доступности биометрии
      checkBiometricAvailability: async () => {
        const availability = await BiometricService.checkAvailability();
        const isEnabled = await BiometricService.isBiometricEnabled();
        
        return {
          available: availability.available,
          type: availability.type,
          enabled: isEnabled
        };
      },

      // Проверка доступности PIN
      checkPinAvailability: async () => {
        const enabled = await PinAuthService.isPinEnabled();
        return { enabled };
      }
    }),
    {
      name: 'auth-storage',
      // Намеренно не персистим user/api: авторизация через сессию (cookies) или JWT при инициализации.
      partialize: (state) => ({})
    }
  )
);

export default useAuthStore;
