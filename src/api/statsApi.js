export function getStats(client, viewContext = null) {
  const params = viewContext ? client._viewParams(viewContext) : {};
  return client.request('stats', params, 'GET');
}

export function getAllWorkoutsSummary(client, viewContext = null) {
  const params = viewContext ? client._viewParams(viewContext) : {};
  return client.request('get_all_workouts_summary', params, 'GET');
}

export function getAllWorkoutsList(client, viewContext = null, limit = 500) {
  const params = viewContext ? client._viewParams(viewContext) : {};
  if (limit) params.limit = limit;
  return client.request('get_all_workouts_list', params, 'GET');
}

export function getRacePrediction(client, viewContext = null) {
  const params = viewContext ? client._viewParams(viewContext) : {};
  return client.request('race_prediction', params, 'GET');
}

export function getIntegrationOAuthUrl(client, provider, extra = {}) {
  return client.request('integration_oauth_url', { provider, ...extra }, 'GET');
}

export async function syncWorkouts(client, provider) {
  const csrfRes = await client.request('get_csrf_token', {}, 'GET');
  const csrfToken = csrfRes?.csrf_token ?? csrfRes?.data?.csrf_token;
  return client.request('sync_workouts', { provider, csrf_token: csrfToken }, 'POST');
}

export function getIntegrationsStatus(client) {
  return client.request('integrations_status', {}, 'GET');
}

export async function unlinkIntegration(client, provider) {
  const csrfRes = await client.request('get_csrf_token', {}, 'GET');
  const csrfToken = csrfRes?.csrf_token ?? csrfRes?.data?.csrf_token;
  return client.request('unlink_integration', { provider, csrf_token: csrfToken }, 'POST');
}

export function getStravaTokenError(client) {
  return client.request('strava_token_error', {}, 'GET');
}

export function getWorkoutTimeline(client, workoutId) {
  return client.request('get_workout_timeline', { workout_id: workoutId }, 'GET');
}

export function getWorkoutShareMap(client, workoutId, options = {}) {
  return client.requestBlob('get_workout_share_map', { workout_id: workoutId, ...options }, 'GET');
}

export function getWorkoutShareCard(client, workoutId, options = {}) {
  return client.requestBlob('generate_workout_share_card', { workout_id: workoutId, ...options }, 'GET');
}

export async function storeWorkoutShareCard(client, workoutId, payload = {}) {
  const csrfRes = await client.request('get_csrf_token', {}, 'GET');
  const csrfToken = csrfRes?.csrf_token ?? csrfRes?.data?.csrf_token;
  return client.request('store_workout_share_card', {
    workout_id: workoutId,
    csrf_token: csrfToken,
    ...payload,
  }, 'POST');
}

export function getTrainingLoad(client, viewContext = null, days = 90) {
  const params = viewContext ? client._viewParams(viewContext) : {};
  if (days) params.days = days;
  return client.request('training_load', params, 'GET');
}

export function runAdaptation(client) {
  return client.request('run_weekly_adaptation', {}, 'GET');
}
