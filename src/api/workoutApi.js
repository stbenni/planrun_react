import { ApiError } from './apiError';
import { isNativeCapacitor } from '../services/TokenStorageService';

async function getCsrfToken(client, message = 'Не удалось получить токен безопасности. Обновите страницу.') {
  const csrfRes = await client.request('get_csrf_token', {}, 'GET');
  const csrfToken = csrfRes?.csrf_token ?? csrfRes?.data?.csrf_token;
  if (!csrfToken) {
    throw new ApiError({ code: 'CSRF_MISSING', message });
  }
  return csrfToken;
}

export function getUserBySlug(client, slug, token = null) {
  const params = { slug: slug.startsWith('@') ? slug.slice(1) : slug };
  if (token) params.token = token;
  return client.request('get_user_by_slug', params, 'GET');
}

export function getDay(client, date, viewContext = null) {
  const params = { date };
  if (viewContext) Object.assign(params, client._viewParams(viewContext));
  return client.request('get_day', params, 'GET');
}

export async function saveResult(client, data, viewContext = null) {
  const csrfToken = await getCsrfToken(client);
  const body = { ...data };
  if (csrfToken) body.csrf_token = csrfToken;
  if (body.activity_type_id == null) body.activity_type_id = 1;
  const urlParams = viewContext ? client._viewParams(viewContext) : {};
  return client.request('save_result', body, 'POST', urlParams);
}

export function getResult(client, date, viewContext = null) {
  const params = { date };
  if (viewContext) Object.assign(params, client._viewParams(viewContext));
  return client.request('get_result', params, 'GET');
}

export async function uploadWorkout(client, file, opts = {}) {
  const csrfRes = await client.request('get_csrf_token', {}, 'GET');
  const csrfToken = csrfRes?.csrf_token ?? csrfRes?.data?.csrf_token;
  const formData = new FormData();
  formData.append('file', file);
  formData.append('date', opts.date || new Date().toISOString().slice(0, 10));
  if (csrfToken) formData.append('csrf_token', csrfToken);
  const token = await client.getToken();
  const headers = {};
  if (token) headers.Authorization = `Bearer ${token}`;
  const url = `${client.baseUrl}/api_wrapper.php?action=upload_workout`;
  const response = await fetch(url, {
    method: 'POST',
    headers,
    credentials: isNativeCapacitor() ? 'omit' : 'include',
    body: formData,
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok || data.success === false) {
    throw new ApiError({ code: 'UPLOAD_FAILED', message: data.error || 'Ошибка загрузки' });
  }
  return data.data || data;
}

export function getAllResults(client, viewContext = null) {
  const params = viewContext ? client._viewParams(viewContext) : {};
  return client.request('get_all_results', params, 'GET');
}

export function resetWorkout(client, date) {
  return client.request('reset', { date }, 'POST');
}

export function deleteWeek(client, weekNumber) {
  return client.request('delete_week', { week: weekNumber }, 'POST');
}

export function addWeek(client, weekData) {
  return client.request('add_week', weekData, 'POST');
}

export function addTrainingDayByDate(client, data, viewContext = null) {
  const urlParams = viewContext ? client._viewParams(viewContext) : {};
  return client.request('add_training_day_by_date', data, 'POST', urlParams);
}

export async function deleteWorkout(client, workoutId, isManual = false, viewContext = null) {
  const csrfToken = await getCsrfToken(client);
  const urlParams = viewContext ? client._viewParams(viewContext) : {};
  return client.request('delete_workout', { workout_id: workoutId, is_manual: isManual, csrf_token: csrfToken }, 'POST', urlParams);
}

export async function deleteTrainingDay(client, dayId, viewContext = null) {
  const csrfToken = await getCsrfToken(client);
  const urlParams = viewContext ? client._viewParams(viewContext) : {};
  return client.request('delete_training_day', { day_id: dayId, csrf_token: csrfToken }, 'POST', urlParams);
}

export async function copyDay(client, sourceDate, targetDate, viewContext = null) {
  const csrfToken = await getCsrfToken(client);
  const urlParams = viewContext ? client._viewParams(viewContext) : {};
  return client.request('copy_day', { source_date: sourceDate, target_date: targetDate, csrf_token: csrfToken }, 'POST', urlParams);
}

export async function copyWeek(client, sourceWeekId, targetStartDate, viewContext = null) {
  const csrfToken = await getCsrfToken(client);
  const urlParams = viewContext ? client._viewParams(viewContext) : {};
  return client.request('copy_week', { source_week_id: sourceWeekId, target_start_date: targetStartDate, csrf_token: csrfToken }, 'POST', urlParams);
}

export function getDayNotes(client, date, viewContext = null) {
  const params = { date };
  if (viewContext) Object.assign(params, client._viewParams(viewContext));
  return client.request('get_day_notes', params, 'GET');
}

export async function saveDayNote(client, date, content, noteId = null, viewContext = null) {
  const csrfToken = await getCsrfToken(client, 'Не удалось получить токен безопасности.');
  const urlParams = viewContext ? client._viewParams(viewContext) : {};
  const body = { date, content, csrf_token: csrfToken };
  if (noteId) body.note_id = noteId;
  return client.request('save_day_note', body, 'POST', urlParams);
}

export async function deleteDayNote(client, noteId, viewContext = null) {
  const csrfToken = await getCsrfToken(client, 'Не удалось получить токен безопасности.');
  const urlParams = viewContext ? client._viewParams(viewContext) : {};
  return client.request('delete_day_note', { note_id: noteId, csrf_token: csrfToken }, 'POST', urlParams);
}

export function getWeekNotes(client, weekStart, viewContext = null) {
  const params = { week_start: weekStart };
  if (viewContext) Object.assign(params, client._viewParams(viewContext));
  return client.request('get_week_notes', params, 'GET');
}

export async function saveWeekNote(client, weekStart, content, noteId = null, viewContext = null) {
  const csrfToken = await getCsrfToken(client, 'Не удалось получить токен безопасности.');
  const urlParams = viewContext ? client._viewParams(viewContext) : {};
  const body = { week_start: weekStart, content, csrf_token: csrfToken };
  if (noteId) body.note_id = noteId;
  return client.request('save_week_note', body, 'POST', urlParams);
}

export async function deleteWeekNote(client, noteId, viewContext = null) {
  const csrfToken = await getCsrfToken(client, 'Не удалось получить токен безопасности.');
  const urlParams = viewContext ? client._viewParams(viewContext) : {};
  return client.request('delete_week_note', { note_id: noteId, csrf_token: csrfToken }, 'POST', urlParams);
}

export function getNoteCounts(client, startDate, endDate, viewContext = null) {
  const params = { start_date: startDate, end_date: endDate };
  if (viewContext) Object.assign(params, client._viewParams(viewContext));
  return client.request('get_note_counts', params, 'GET');
}

export function getPlanNotifications(client) {
  return client.request('get_plan_notifications', {}, 'GET');
}

export function markPlanNotificationRead(client, notificationId) {
  return client.request('mark_plan_notification_read', { notification_id: notificationId }, 'POST');
}

export function markAllPlanNotificationsRead(client) {
  return client.request('mark_plan_notification_read', { all: true }, 'POST');
}

export async function updateTrainingDay(client, dayId, data, viewContext = null) {
  const csrfToken = await getCsrfToken(client);
  const urlParams = viewContext ? client._viewParams(viewContext) : {};
  return client.request('update_training_day', {
    day_id: dayId,
    type: data.type,
    description: data.description,
    is_key_workout: data.is_key_workout != null ? (data.is_key_workout ? 1 : 0) : undefined,
    csrf_token: csrfToken,
  }, 'POST', urlParams);
}
