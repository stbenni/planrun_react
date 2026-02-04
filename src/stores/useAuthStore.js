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

      // Инициализация
      initialize: async () => {
        try {
          const apiClient = new ApiClient();
          
          // Для мобильных приложений проверяем биометрию
          if (typeof window !== 'undefined' && window.Capacitor) {
            try {
              const isEnabled = await BiometricService.isBiometricEnabled();
              
              if (isEnabled) {
                const result = await BiometricService.authenticateAndGetTokens(
                  'Войдите в PlanRun'
                );
                
                if (result.success && result.tokens) {
                  await apiClient.setToken(result.tokens.accessToken, result.tokens.refreshToken);
                  const userData = await apiClient.getCurrentUser();
                  if (userData && userData.authenticated) {
                    set({ 
                      user: userData, 
                      api: apiClient, 
                      isAuthenticated: true,
                      loading: false 
                    });
                    return;
                  }
                }
              }
            } catch (error) {
              console.log('Biometric auto-login failed:', error);
            }
          }
          
          // Проверяем авторизацию через PHP сессию (cookies) или JWT
          try {
            const userData = await apiClient.getCurrentUser();
            if (userData && userData.authenticated) {
              set({ 
                user: userData, 
                api: apiClient, 
                isAuthenticated: true 
              });
            }
          } catch (error) {
            console.log('User not authenticated:', error.message);
          }
          
          // Обработчик истечения токена
          apiClient.onTokenExpired = async () => {
            await get().logout();
          };
          
          set({ api: apiClient, loading: false });
        } catch (error) {
          console.error('Error initializing app:', error);
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
            
            // Если это мобильное приложение и биометрия доступна, сохраняем токены
            if (useJwt && result.access_token && result.refresh_token) {
              const availability = await BiometricService.checkAvailability();
              if (availability.available) {
                await BiometricService.saveTokens(result.access_token, result.refresh_token);
              }
            }
            
            set({ 
              user, 
              isAuthenticated: true 
            });
            
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
      partialize: (state) => ({
        // Сохраняем только минимально необходимое
        // API клиент не сохраняем, так как он не сериализуется
      })
    }
  )
);

export default useAuthStore;
