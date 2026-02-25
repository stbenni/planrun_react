/**
 * Store для управления авторизацией
 * Использует Zustand для глобального состояния
 */

import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import ApiClient from '../api/ApiClient';
import BiometricService from '../services/BiometricService';

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

      // Инициализация
      initialize: async () => {
        const apiClient = new ApiClient();
        // Обработчик истечения токена выставляем до любых запросов
        apiClient.onTokenExpired = async () => {
          await get().logout();
        };
        // api в store всегда задаём, чтобы форма логина работала даже при ошибке getCurrentUser
        set({ api: apiClient });

        try {
          // На Android не показываем биометрический диалог при старте — он часто зависает.
          // Токены подхватятся из хранилища в getCurrentUser() без диалога.
          const isAndroid = typeof window !== 'undefined' && window.Capacitor?.getPlatform?.() === 'android';
          const canAutoBiometric = typeof window !== 'undefined' && window.Capacitor && !isAndroid;

          if (canAutoBiometric) {
            try {
              const isEnabled = await BiometricService.isBiometricEnabled();
              if (isEnabled) {
                const BIOMETRIC_INIT_TIMEOUT_MS = 10000;
                const result = await Promise.race([
                  BiometricService.authenticateAndGetTokens('Войдите в PlanRun'),
                  new Promise((_, reject) =>
                    setTimeout(() => reject(new Error('Biometric timeout')), BIOMETRIC_INIT_TIMEOUT_MS)
                  )
                ]).catch((err) => {
                  if (err?.message === 'Biometric timeout') console.log('[Auth] Biometric init timeout');
                  return { success: false };
                });

                if (result?.success && result?.tokens) {
                  await apiClient.setToken(result.tokens.accessToken, result.tokens.refreshToken);
                  const userData = await apiClient.getCurrentUser();
                  if (userData?.authenticated) {
                    set({ user: userData, isAuthenticated: true, loading: false });
                    return;
                  }
                }
              }
            } catch (error) {
              console.log('Biometric auto-login failed:', error?.message || error);
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
            }
          } catch (error) {
            console.log('User not authenticated:', error.message);
          }
        } catch (error) {
          console.error('Error initializing app:', error);
        } finally {
          set({ loading: false });
        }
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
            if (useJwt && result.access_token && result.refresh_token) {
              const availability = await BiometricService.checkAvailability();
              if (availability.available) {
                await BiometricService.saveTokens(result.access_token, result.refresh_token);
              }
            }
            set({ user, isAuthenticated: true });
            // Подтягиваем полного пользователя (в т.ч. avatar_path) — ответ login его не содержит
            try {
              const fullUser = await get().api.getCurrentUser();
              if (fullUser && fullUser.authenticated) {
                set({ user: fullUser });
              }
            } catch (_) {
              // не блокируем вход
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
      logout: async () => {
        const { api } = get();
        
        try {
          if (api) {
            await api.logout();
          }
          
          // Очищаем биометрические токены
          await BiometricService.clearTokens();
          
          set({ 
            user: null, 
            isAuthenticated: false 
          });
        } catch (error) {
          console.error('Logout error:', error);
          // Все равно очищаем состояние
          set({ 
            user: null, 
            isAuthenticated: false 
          });
        }
      },

      // Биометрический вход
      biometricLogin: async () => {
        try {
          const result = await BiometricService.authenticateAndGetTokens(
            'Используйте биометрию для входа в PlanRun'
          );

          if (result.success && result.tokens) {
            const { api } = get();
            if (!api) {
              throw new Error('API client not initialized');
            }

            await api.setToken(result.tokens.accessToken, result.tokens.refreshToken);
            
            const userData = await api.getCurrentUser();
            if (userData && userData.authenticated) {
              set({ 
                user: userData, 
                isAuthenticated: true 
              });
              return { success: true };
            } else {
              await BiometricService.clearTokens();
              return { 
                success: false, 
                error: 'Токены недействительны. Пожалуйста, войдите заново' 
              };
            }
          }
          
          return { 
            success: false, 
            error: result.error || 'Биометрическая аутентификация не прошла' 
          };
        } catch (error) {
          return { 
            success: false, 
            error: error.message || 'Произошла ошибка при биометрической аутентификации' 
          };
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
