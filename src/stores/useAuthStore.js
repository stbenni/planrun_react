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

      // Инициализация
      initialize: async () => {
        const apiClient = new ApiClient();
        // Обработчик истечения токена выставляем до любых запросов
        apiClient.onTokenExpired = async () => {
          if (get()._unlocking) return;
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

          // Быстрый путь: на native без токенов — сразу логин
          // Проверяем localStorage и TokenStorage (бэкап в Preferences переживает обновление)
          if (isNative) {
            const hasLocal = typeof localStorage !== 'undefined' && !!localStorage.getItem('auth_token');
            if (!hasLocal) {
              try {
                const stored = await TokenStorageService.getTokens();
                if (!stored?.accessToken || !stored?.refreshToken) {
                  set({ loading: false });
                  clearTimeout(safetyTimeout);
                  return;
                }
                // Токены есть в бэкапе — синхронизируем в localStorage для ApiClient
                if (typeof localStorage !== 'undefined') {
                  localStorage.setItem('auth_token', stored.accessToken);
                  localStorage.setItem('refresh_token', stored.refreshToken);
                }
              } catch (_) {
                set({ loading: false });
                clearTimeout(safetyTimeout);
                return;
              }
            }
          }

          // Native: при включённых PIN или биометрии показываем экран блокировки
          if (isNative) {
            const [pinEnabled, biometricEnabled] = await Promise.all([
              PinAuthService.isPinEnabled(),
              BiometricService.isBiometricEnabled()
            ]);
            if (pinEnabled || biometricEnabled) {
              set({ isLocked: true, loading: false });
              get().setupBackgroundLock?.();
              clearTimeout(safetyTimeout);
              return;
            }
            // Capacitor web или токены есть — проверяем авторизацию
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
                  get().setupBackgroundLock?.();
                }
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

      /** Настроить блокировку при уходе в фон на 15 мин (только Capacitor, при PIN/биометрии) */
      setupBackgroundLock: () => {
        if (get()._backgroundLockSetup) return;
        set({ _backgroundLockSetup: true, lastActiveAt: Date.now() });
        const LOCK_AFTER_MS = 15 * 60 * 1000;
        // Синхронная проверка: если время вышло — блокируем немедленно (без мигания контента)
        const checkAndLockSync = () => {
          const { isLocked, lastActiveAt } = get();
          if (isLocked) return;
          if (lastActiveAt && Date.now() - lastActiveAt > LOCK_AFTER_MS) {
            set({ isLocked: true });
          }
        };
        const onBackground = () => set({ lastActiveAt: Date.now() });
        const onForeground = () => {
          checkAndLockSync();
          const { isAuthenticated, isLocked, api } = get();
          if (!api) return;
          if (isLocked) {
            api.refreshAccessToken().catch(() => {});
          } else if (isAuthenticated) {
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

        await api.setToken(tokens.accessToken, tokens.refreshToken);

        let userData;
        try {
          userData = await api.getCurrentUser();
        } catch (authErr) {
          const isNetworkErr = authErr?.code === 'NETWORK_ERROR' || /нет соединения|network|fetch/i.test(authErr?.message || '');
          if (isNetworkErr) throw authErr;
          userData = null;
        }

        if (!userData?.authenticated && recoveryFn) {
          try {
            const recover = await recoveryFn(api);
            if (recover?.success) {
              userData = await api.getCurrentUser();
            }
          } catch (_) {}
        }

        if (userData?.authenticated) {
          set({ user: userData, isAuthenticated: true, isLocked: false });
          // План и push — в фоне, не блокируя разблокировку
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

          if (!result.success || !result.tokens) {
            return {
              success: false,
              error: result.error || 'Биометрическая аутентификация не прошла'
            };
          }

          return await get()._completeUnlock(result.tokens, async (api) => {
            if (await CredentialBackupService.hasCredentials()) {
              return CredentialBackupService.recoverAndLoginBiometric(api);
            }
            return { success: false };
          });
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
