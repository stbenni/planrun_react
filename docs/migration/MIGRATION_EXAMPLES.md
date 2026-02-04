# 💻 Примеры кода для миграции

## 1. Миграция на Laravel: Структура API

### Текущий код (api.php)
```php
// api.php - 2760 строк в одном файле
if ($action === 'load') {
    $userId = getCurrentUserId();
    $db = getDBConnection();
    // ... 100+ строк кода
}
```

### Новый код (Laravel)

#### Контроллер
```php
// app/Http/Controllers/Api/TrainingPlanController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrainingPlan;
use App\Http\Requests\StoreTrainingPlanRequest;
use Illuminate\Http\JsonResponse;

class TrainingPlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plan = TrainingPlan::where('user_id', auth()->id())
            ->with(['weeks.days.exercises'])
            ->first();
            
        return response()->json([
            'success' => true,
            'data' => $plan
        ]);
    }
    
    public function store(StoreTrainingPlanRequest $request): JsonResponse
    {
        $plan = TrainingPlan::create([
            'user_id' => auth()->id(),
            'goal_type' => $request->goal_type,
            'weeks' => $request->weeks,
        ]);
        
        return response()->json([
            'success' => true,
            'data' => $plan
        ], 201);
    }
}
```

#### Модель
```php
// app/Models/TrainingPlan.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingPlan extends Model
{
    protected $fillable = [
        'user_id',
        'goal_type',
        'start_date',
        'target_date',
    ];
    
    protected $casts = [
        'start_date' => 'date',
        'target_date' => 'date',
    ];
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function weeks(): HasMany
    {
        return $this->hasMany(TrainingWeek::class);
    }
}
```

#### Валидация
```php
// app/Http/Requests/StoreTrainingPlanRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTrainingPlanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'goal_type' => 'required|in:race,general,weight_loss',
            'race_distance' => 'required_if:goal_type,race',
            'start_date' => 'required|date',
            'target_date' => 'required|date|after:start_date',
            'weeks' => 'required|array|min:1',
            'weeks.*.days' => 'required|array',
        ];
    }
}
```

#### Роуты
```php
// routes/api.php
use App\Http\Controllers\Api\TrainingPlanController;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('training-plans', TrainingPlanController::class);
    Route::post('training-plans/{plan}/regenerate', [TrainingPlanController::class, 'regenerate']);
});
```

---

## 2. Миграция на TypeScript

### Текущий код (JavaScript)
```javascript
// src/api/ApiClient.js
class ApiClient {
  async getPlan(userId = null) {
    const params = userId ? { user_id: userId } : {};
    return this.request('load', params, 'GET');
  }
}
```

### Новый код (TypeScript)
```typescript
// src/api/ApiClient.ts
interface TrainingPlan {
  id: number;
  user_id: number;
  goal_type: 'race' | 'general' | 'weight_loss';
  weeks: TrainingWeek[];
}

interface TrainingWeek {
  week_number: number;
  days: TrainingDay[];
}

interface ApiResponse<T> {
  success: boolean;
  data?: T;
  error?: string;
}

class ApiClient {
  async getPlan(userId?: number): Promise<TrainingPlan> {
    const params = userId ? { user_id: userId } : {};
    const response = await this.request<ApiResponse<TrainingPlan>>('load', params, 'GET');
    
    if (!response.success || !response.data) {
      throw new Error(response.error || 'Failed to load plan');
    }
    
    return response.data;
  }
  
  private async request<T>(
    action: string, 
    params: Record<string, any>, 
    method: 'GET' | 'POST' = 'GET'
  ): Promise<T> {
    // ... implementation
  }
}
```

---

## 3. State Management с Zustand

### Текущий код (локальное состояние)
```javascript
// App.jsx
const [user, setUser] = useState(null);
const [plan, setPlan] = useState(null);

// Передача через props во все компоненты
```

### Новый код (Zustand)
```typescript
// src/stores/useAuthStore.ts
import create from 'zustand';
import { persist } from 'zustand/middleware';

interface User {
  id: number;
  username: string;
  authenticated: boolean;
}

interface AuthState {
  user: User | null;
  login: (username: string, password: string) => Promise<void>;
  logout: () => void;
  isAuthenticated: () => boolean;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      user: null,
      
      login: async (username: string, password: string) => {
        const api = new ApiClient();
        const result = await api.login(username, password);
        set({ user: result.user });
      },
      
      logout: async () => {
        const api = new ApiClient();
        await api.logout();
        set({ user: null });
      },
      
      isAuthenticated: () => {
        const state = useAuthStore.getState();
        return state.user?.authenticated === true;
      },
    }),
    {
      name: 'auth-storage',
    }
  )
);
```

### Использование в компонентах
```typescript
// src/screens/DashboardScreen.tsx
import { useAuthStore } from '../stores/useAuthStore';

function DashboardScreen() {
  const { user, logout } = useAuthStore();
  
  if (!user) {
    return <Navigate to="/login" />;
  }
  
  return (
    <div>
      <h1>Welcome, {user.username}</h1>
      <button onClick={logout}>Logout</button>
    </div>
  );
}
```

---

## 4. React Query для серверного состояния

### Текущий код (useState + useEffect)
```javascript
// CalendarScreen.jsx
const [workouts, setWorkouts] = useState([]);
const [loading, setLoading] = useState(true);

useEffect(() => {
  async function loadWorkouts() {
    setLoading(true);
    try {
      const data = await api.getDay(date);
      setWorkouts(data);
    } catch (error) {
      console.error(error);
    } finally {
      setLoading(false);
    }
  }
  loadWorkouts();
}, [date]);
```

### Новый код (React Query)
```typescript
// src/hooks/useWorkouts.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '../api/ApiClient';

export function useWorkout(date: string) {
  return useQuery({
    queryKey: ['workout', date],
    queryFn: () => api.getDay(date),
    staleTime: 5 * 60 * 1000, // 5 минут
  });
}

export function useSaveWorkout() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ date, result }: { date: string; result: any }) =>
      api.saveResult(date, result),
    onSuccess: (_, variables) => {
      // Инвалидировать кеш после сохранения
      queryClient.invalidateQueries({ queryKey: ['workout', variables.date] });
      queryClient.invalidateQueries({ queryKey: ['stats'] });
    },
  });
}
```

### Использование в компоненте
```typescript
// src/screens/CalendarScreen.tsx
import { useWorkout, useSaveWorkout } from '../hooks/useWorkouts';

function CalendarScreen() {
  const [selectedDate, setSelectedDate] = useState(new Date().toISOString().split('T')[0]);
  const { data: workout, isLoading, error } = useWorkout(selectedDate);
  const saveWorkout = useSaveWorkout();
  
  const handleSave = async (result: any) => {
    await saveWorkout.mutateAsync({ date: selectedDate, result });
  };
  
  if (isLoading) return <Spinner />;
  if (error) return <ErrorMessage error={error} />;
  
  return (
    <div>
      <WorkoutCard workout={workout} onSave={handleSave} />
    </div>
  );
}
```

---

## 5. JWT аутентификация (Laravel Sanctum)

### Текущий код (PHP сессии)
```php
// auth.php
function login($username, $password) {
    // ...
    $_SESSION['authenticated'] = true;
    $_SESSION['user_id'] = $user['id'];
    return true;
}
```

### Новый код (Laravel Sanctum)
```php
// app/Http/Controllers/Api/AuthController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('username', $request->username)->first();
        
        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['Неверный логин или пароль'],
            ]);
        }
        
        $token = $user->createToken('api-token')->plainTextToken;
        
        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'token' => $token,
            ],
        ]);
    }
    
    public function logout(): JsonResponse
    {
        auth()->user()->currentAccessToken()->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Вы успешно вышли из системы',
        ]);
    }
}
```

### Frontend (TypeScript)
```typescript
// src/api/ApiClient.ts
class ApiClient {
  private token: string | null = null;
  
  async login(username: string, password: string): Promise<AuthResponse> {
    const response = await fetch('/api/v1/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
    });
    
    const data = await response.json();
    
    if (data.success && data.data.token) {
      this.token = data.data.token;
      localStorage.setItem('auth_token', this.token);
    }
    
    return data;
  }
  
  private getAuthHeaders(): HeadersInit {
    const headers: HeadersInit = {
      'Content-Type': 'application/json',
    };
    
    if (this.token) {
      headers['Authorization'] = `Bearer ${this.token}`;
    }
    
    return headers;
  }
}
```

---

## 6. Миграция на PostgreSQL

### Миграция схемы
```sql
-- Создание таблицы в PostgreSQL
CREATE TABLE training_plans (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id),
    goal_type VARCHAR(50) NOT NULL,
    start_date DATE NOT NULL,
    target_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- JSONB колонка для гибкого хранения данных
ALTER TABLE training_plans 
ADD COLUMN metadata JSONB DEFAULT '{}'::jsonb;

-- Индекс для JSONB запросов
CREATE INDEX idx_training_plans_metadata 
ON training_plans USING GIN (metadata);

-- Пример запроса с JSONB
SELECT * FROM training_plans 
WHERE metadata->>'ai_model' = 'qwen3';
```

### Laravel Migration
```php
// database/migrations/2026_01_25_create_training_plans_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('goal_type');
            $table->date('start_date');
            $table->date('target_date');
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
            
            $table->index(['user_id', 'created_at']);
            $table->index('metadata', null, 'gin');
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('training_plans');
    }
};
```

---

## 7. Тестирование (PHPUnit)

### Unit тест
```php
// tests/Unit/Services/PlanGeneratorServiceTest.php
namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\PlanGeneratorService;
use App\Models\User;

class PlanGeneratorServiceTest extends TestCase
{
    public function test_generates_plan_for_beginner(): void
    {
        $user = User::factory()->create();
        $service = new PlanGeneratorService();
        
        $plan = $service->generate([
            'user_id' => $user->id,
            'level' => 'beginner',
            'goal' => 'marathon',
            'weekly_base_km' => 20,
        ]);
        
        $this->assertInstanceOf(\App\Models\TrainingPlan::class, $plan);
        $this->assertEquals($user->id, $plan->user_id);
        $this->assertGreaterThanOrEqual(12, $plan->weeks->count());
    }
}
```

### Feature тест (API)
```php
// tests/Feature/Api/TrainingPlanTest.php
namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

class TrainingPlanTest extends TestCase
{
    public function test_user_can_get_training_plan(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        
        $response = $this->getJson('/api/v1/training-plans');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'user_id',
                    'goal_type',
                ],
            ]);
    }
}
```

---

## 8. CI/CD (GitHub Actions)

```yaml
# .github/workflows/ci.yml
name: CI/CD Pipeline

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main]

jobs:
  test-backend:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:18
        env:
          POSTGRES_PASSWORD: postgres
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5
    
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo, pdo_pgsql
      
      - name: Install dependencies
        run: composer install
      
      - name: Run tests
        env:
          DB_CONNECTION: pgsql
          DB_HOST: postgres
          DB_DATABASE: test_db
          DB_USERNAME: postgres
          DB_PASSWORD: postgres
        run: php artisan test

  test-frontend:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup Node
        uses: actions/setup-node@v3
        with:
          node-version: '20'
      
      - name: Install dependencies
        run: npm ci
      
      - name: Run tests
        run: npm test
      
      - name: Build
        run: npm run build
      
      - name: Upload build artifacts
        uses: actions/upload-artifact@v3
        with:
          name: build
          path: dist/
```

---

## 9. Конфигурация через .env

### Laravel .env
```env
# .env
APP_NAME=PlanRun
APP_ENV=production
APP_DEBUG=false
APP_URL=https://planrun.ru

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=running_calendar
DB_USERNAME=planrun_user
DB_PASSWORD=${DB_PASSWORD}

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

JWT_SECRET=${JWT_SECRET}
JWT_EXPIRES_IN=3600

PLANRUN_AI_API_URL=http://localhost:8000/api/v1/generate-plan
```

### Использование в коде
```php
// config/database.php
return [
    'default' => env('DB_CONNECTION', 'mysql'),
    
    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE'),
            'username' => env('DB_USERNAME'),
            'password' => env('DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
        ],
    ],
];
```

---

## 10. Биометрическая аутентификация (Отпечаток пальца)

### Установка плагина
```bash
npm install @capawesome-team/capacitor-biometrics
npx cap sync
```

### Настройка для Android
```xml
<!-- android/app/src/main/AndroidManifest.xml -->
<uses-permission android:name="android.permission.USE_BIOMETRIC" />
<uses-permission android:name="android.permission.USE_FINGERPRINT" />
```

### Настройка для iOS
```xml
<!-- ios/App/App/Info.plist -->
<key>NSFaceIDUsageDescription</key>
<string>Используйте Face ID для быстрого входа в приложение</string>
```

### Сервис биометрической аутентификации
```typescript
// src/services/BiometricAuthService.ts
import { Biometrics } from '@capawesome-team/capacitor-biometrics';
import { Preferences } from '@capacitor/preferences';
import { ApiClient } from '../api/ApiClient';

interface StoredCredentials {
  token: string;
  refreshToken: string;
  username: string;
  expiresAt: number;
}

export class BiometricAuthService {
  private readonly CREDENTIALS_KEY = 'biometric_credentials';
  
  /**
   * Проверка доступности биометрии на устройстве
   */
  async isAvailable(): Promise<boolean> {
    try {
      const result = await Biometrics.checkBiometry();
      return result.isAvailable;
    } catch (error) {
      console.error('Biometric check failed:', error);
      return false;
    }
  }
  
  /**
   * Получить тип доступной биометрии
   */
  async getBiometryType(): Promise<string> {
    try {
      const result = await Biometrics.checkBiometry();
      return result.biometryType || 'none';
    } catch {
      return 'none';
    }
  }
  
  /**
   * Сохранение учетных данных после успешного входа
   */
  async saveCredentials(
    username: string,
    token: string,
    refreshToken: string,
    expiresIn: number = 3600
  ): Promise<void> {
    const credentials: StoredCredentials = {
      username,
      token,
      refreshToken,
      expiresAt: Date.now() + expiresIn * 1000,
    };
    
    // Сохраняем в защищенном хранилище через биометрию
    const encrypted = JSON.stringify(credentials);
    
    try {
      // Используем биометрию для шифрования данных
      await Biometrics.setCredentials({
        username: this.CREDENTIALS_KEY,
        password: encrypted,
        server: 'planrun.app',
      });
      
      // Также сохраняем флаг, что биометрия включена
      await Preferences.set({
        key: 'biometric_enabled',
        value: 'true',
      });
    } catch (error) {
      console.error('Failed to save credentials:', error);
      throw new Error('Не удалось сохранить учетные данные');
    }
  }
  
  /**
   * Аутентификация через биометрию
   */
  async authenticateWithBiometry(): Promise<StoredCredentials | null> {
    try {
      // Проверяем доступность биометрии
      const available = await this.isAvailable();
      if (!available) {
        throw new Error('Биометрия недоступна на этом устройстве');
      }
      
      // Запрашиваем биометрическую аутентификацию
      const result = await Biometrics.authenticate({
        reason: 'Войдите в PlanRun',
        title: 'Биометрическая аутентификация',
        subtitle: 'Используйте отпечаток пальца для входа',
        description: 'Приложите палец к сенсору',
        negativeButtonText: 'Отмена',
        maxAttempts: 3,
      });
      
      if (!result.succeeded) {
        return null;
      }
      
      // Получаем сохраненные учетные данные
      const credentials = await this.getStoredCredentials();
      
      if (!credentials) {
        throw new Error('Учетные данные не найдены');
      }
      
      // Проверяем срок действия токена
      if (credentials.expiresAt < Date.now()) {
        // Токен истек, нужно обновить
        const refreshed = await this.refreshToken(credentials.refreshToken);
        if (refreshed) {
          return refreshed;
        }
        throw new Error('Токен истек. Требуется повторный вход');
      }
      
      return credentials;
    } catch (error: any) {
      if (error.code === 'USER_CANCEL') {
        // Пользователь отменил аутентификацию
        return null;
      }
      
      if (error.code === 'BIOMETRIC_AUTHENTICATION_FAILED') {
        throw new Error('Биометрическая аутентификация не удалась');
      }
      
      console.error('Biometric authentication error:', error);
      throw error;
    }
  }
  
  /**
   * Получить сохраненные учетные данные (без биометрии)
   */
  private async getStoredCredentials(): Promise<StoredCredentials | null> {
    try {
      const result = await Biometrics.getCredentials({
        username: this.CREDENTIALS_KEY,
        server: 'planrun.app',
      });
      
      if (!result.password) {
        return null;
      }
      
      return JSON.parse(result.password) as StoredCredentials;
    } catch (error) {
      console.error('Failed to get credentials:', error);
      return null;
    }
  }
  
  /**
   * Обновление токена через refresh token
   */
  private async refreshToken(refreshToken: string): Promise<StoredCredentials | null> {
    try {
      const api = new ApiClient();
      // Предполагаем, что есть endpoint для обновления токена
      const response = await api.refreshToken(refreshToken);
      
      if (response.success && response.data) {
        // Сохраняем новые учетные данные
        await this.saveCredentials(
          response.data.username,
          response.data.token,
          response.data.refreshToken,
          response.data.expiresIn
        );
        
        return {
          username: response.data.username,
          token: response.data.token,
          refreshToken: response.data.refreshToken,
          expiresAt: Date.now() + response.data.expiresIn * 1000,
        };
      }
      
      return null;
    } catch (error) {
      console.error('Token refresh failed:', error);
      return null;
    }
  }
  
  /**
   * Удаление сохраненных учетных данных
   */
  async clearCredentials(): Promise<void> {
    try {
      await Biometrics.deleteCredentials({
        username: this.CREDENTIALS_KEY,
        server: 'planrun.app',
      });
      
      await Preferences.remove({ key: 'biometric_enabled' });
    } catch (error) {
      console.error('Failed to clear credentials:', error);
    }
  }
  
  /**
   * Проверка, включена ли биометрия
   */
  async isBiometricEnabled(): Promise<boolean> {
    const { value } = await Preferences.get({ key: 'biometric_enabled' });
    return value === 'true';
  }
}
```

### Интеграция в ApiClient
```typescript
// src/api/ApiClient.ts - добавление методов
import { BiometricAuthService } from '../services/BiometricAuthService';

class ApiClient {
  private biometricAuth = new BiometricAuthService();
  
  /**
   * Вход с возможностью сохранения для биометрии
   */
  async login(
    username: string, 
    password: string,
    enableBiometric: boolean = false
  ): Promise<AuthResponse> {
    const response = await fetch('/api/v1/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
    });
    
    const data = await response.json();
    
    if (data.success && data.data.token) {
      this.token = data.data.token;
      
      // Сохраняем для биометрии, если пользователь включил
      if (enableBiometric) {
        try {
          await this.biometricAuth.saveCredentials(
            username,
            data.data.token,
            data.data.refreshToken,
            data.data.expiresIn
          );
        } catch (error) {
          console.warn('Failed to save biometric credentials:', error);
        }
      }
    }
    
    return data;
  }
  
  /**
   * Быстрый вход через биометрию
   */
  async loginWithBiometry(): Promise<AuthResponse | null> {
    try {
      const credentials = await this.biometricAuth.authenticateWithBiometry();
      
      if (!credentials) {
        return null;
      }
      
      // Устанавливаем токен
      this.token = credentials.token;
      localStorage.setItem('auth_token', this.token);
      
      // Получаем данные пользователя
      const userData = await this.getCurrentUser();
      
      return {
        success: true,
        data: {
          user: userData,
          token: credentials.token,
        },
      };
    } catch (error: any) {
      throw new ApiError({
        code: 'BIOMETRIC_AUTH_FAILED',
        message: error.message || 'Биометрическая аутентификация не удалась',
      });
    }
  }
  
  /**
   * Обновление токена
   */
  async refreshToken(refreshToken: string): Promise<AuthResponse> {
    const response = await fetch('/api/v1/auth/refresh', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ refresh_token: refreshToken }),
    });
    
    return response.json();
  }
}
```

### Использование в компонентах
```typescript
// src/screens/LoginScreen.tsx
import { useState, useEffect } from 'react';
import { BiometricAuthService } from '../services/BiometricAuthService';
import { ApiClient } from '../api/ApiClient';

function LoginScreen() {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [biometricAvailable, setBiometricAvailable] = useState(false);
  const [biometricEnabled, setBiometricEnabled] = useState(false);
  const [biometricType, setBiometricType] = useState<string>('none');
  
  const api = new ApiClient();
  const biometricAuth = new BiometricAuthService();
  
  useEffect(() => {
    checkBiometricAvailability();
  }, []);
  
  const checkBiometricAvailability = async () => {
    const available = await biometricAuth.isAvailable();
    const enabled = await biometricAuth.isBiometricEnabled();
    const type = await biometricAuth.getBiometryType();
    
    setBiometricAvailable(available);
    setBiometricEnabled(enabled);
    setBiometricType(type);
  };
  
  const handleBiometricLogin = async () => {
    try {
      const result = await api.loginWithBiometry();
      if (result?.success) {
        // Перенаправление на главный экран
        navigate('/');
      }
    } catch (error: any) {
      alert(error.message || 'Ошибка биометрической аутентификации');
    }
  };
  
  const handleRegularLogin = async () => {
    try {
      const result = await api.login(username, password, true); // Включаем биометрию
      if (result.success) {
        navigate('/');
      }
    } catch (error: any) {
      alert(error.message || 'Ошибка входа');
    }
  };
  
  const getBiometricIcon = () => {
    switch (biometricType) {
      case 'FINGERPRINT':
      case 'TOUCH_ID':
        return '👆'; // Иконка отпечатка
      case 'FACE_ID':
        return '😊'; // Иконка лица
      case 'FACE_AUTHENTICATION':
        return '👁️'; // Иконка лица (Android)
      default:
        return '🔐';
    }
  };
  
  return (
    <div className="login-screen">
      <h1>Вход в PlanRun</h1>
      
      <form onSubmit={(e) => { e.preventDefault(); handleRegularLogin(); }}>
        <input
          type="text"
          placeholder="Логин"
          value={username}
          onChange={(e) => setUsername(e.target.value)}
        />
        <input
          type="password"
          placeholder="Пароль"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />
        <button type="submit">Войти</button>
      </form>
      
      {/* Кнопка быстрого входа через биометрию */}
      {biometricAvailable && biometricEnabled && (
        <button 
          className="biometric-button"
          onClick={handleBiometricLogin}
        >
          <span className="biometric-icon">{getBiometricIcon()}</span>
          <span>Войти через {biometricType === 'FINGERPRINT' ? 'отпечаток' : 'биометрию'}</span>
        </button>
      )}
      
      {/* Переключатель включения биометрии */}
      {biometricAvailable && !biometricEnabled && (
        <label>
          <input
            type="checkbox"
            checked={biometricEnabled}
            onChange={(e) => {
              // После первого входа можно включить биометрию
              setBiometricEnabled(e.target.checked);
            }}
          />
          Включить вход через {biometricType === 'FINGERPRINT' ? 'отпечаток пальца' : 'биометрию'}
        </label>
      )}
    </div>
  );
}
```

### Backend: Endpoint для обновления токена
```php
// app/Http/Controllers/Api/AuthController.php - добавление метода
public function refresh(Request $request): JsonResponse
{
    $request->validate([
        'refresh_token' => 'required|string',
    ]);
    
    try {
        // Проверяем refresh token
        $refreshToken = $request->input('refresh_token');
        $tokenRecord = PersonalAccessToken::findToken($refreshToken);
        
        if (!$tokenRecord || $tokenRecord->expires_at < now()) {
            return response()->json([
                'success' => false,
                'error' => 'Недействительный refresh token',
            ], 401);
        }
        
        $user = $tokenRecord->tokenable;
        
        // Удаляем старый токен
        $tokenRecord->delete();
        
        // Создаем новый токен
        $newToken = $user->createToken('api-token')->plainTextToken;
        $newRefreshToken = $user->createToken('refresh-token', ['refresh'])->plainTextToken;
        
        return response()->json([
            'success' => true,
            'data' => [
                'token' => $newToken,
                'refresh_token' => $newRefreshToken,
                'expires_in' => 3600,
            ],
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => 'Ошибка обновления токена',
        ], 500);
    }
}
```

### Настройка роутов
```php
// routes/api.php
Route::post('/auth/refresh', [AuthController::class, 'refresh']);
```

## 11. Оптимизация запросов (Eager Loading)

### Проблема N+1 (текущий код)
```php
// Загружает план
$plan = TrainingPlan::find($id);

// Затем для каждой недели делает отдельный запрос
foreach ($plan->weeks as $week) {
    foreach ($week->days as $day) {
        // Еще больше запросов
        $exercises = $day->exercises;
    }
}
// Итого: 1 + N + N*M запросов
```

### Решение (Eager Loading)
```php
// Один запрос со всеми связями
$plan = TrainingPlan::with([
    'weeks.days.exercises',
    'weeks.days.workout'
])->find($id);

// Или через Query Builder
$plan = TrainingPlan::query()
    ->with([
        'weeks' => function ($query) {
            $query->orderBy('week_number');
        },
        'weeks.days' => function ($query) {
            $query->orderBy('date');
        },
        'weeks.days.exercises' => function ($query) {
            $query->orderBy('order_index');
        },
    ])
    ->where('user_id', auth()->id())
    ->first();
```

---

## 📝 Следующие шаги

1. Выберите один из примеров для начала
2. Создайте feature branch
3. Реализуйте изменения
4. Напишите тесты
5. Создайте Pull Request

**Рекомендуемый порядок:**
1. Конфигурация (.env)
2. Базовое тестирование
3. TypeScript миграция
4. State Management
5. Backend рефакторинг
