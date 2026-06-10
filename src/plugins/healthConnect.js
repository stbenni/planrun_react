import { registerPlugin } from '@capacitor/core';

/**
 * Нативный плагин Health Connect (Android). Реализация — android/.../HealthConnectPlugin.kt.
 * На web/iOS методы отсутствуют — всегда вызывать через healthConnectSync.js с гвардом isNativeCapacitor().
 */
const HealthConnect = registerPlugin('HealthConnect');

export default HealthConnect;
