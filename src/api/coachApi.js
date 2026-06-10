export function listCoaches(client, params = {}) {
  return client.request('list_coaches', params, 'GET');
}

export function requestCoach(client, coachId, message = '') {
  return client.request('request_coach', { coach_id: coachId, message }, 'POST');
}

export function getCoachRequests(client, params = {}) {
  return client.request('coach_requests', params, 'GET');
}

export function acceptCoachRequest(client, requestId) {
  return client.request('accept_coach_request', { request_id: requestId }, 'POST');
}

export function rejectCoachRequest(client, requestId) {
  return client.request('reject_coach_request', { request_id: requestId }, 'POST');
}

export function getMyCoaches(client) {
  return client.request('get_my_coaches', {}, 'GET');
}

export function removeCoach(client, { coachId, athleteId } = {}) {
  const body = {};
  if (coachId) body.coach_id = coachId;
  if (athleteId) body.athlete_id = athleteId;
  return client.request('remove_coach', body, 'POST');
}

export function applyCoach(client, data) {
  return client.request('apply_coach', data, 'POST');
}

export function getCoachAthletes(client) {
  return client.request('coach_athletes', {}, 'GET');
}

export function getAthleteDetails(client, athleteId, weekStart = null) {
  const params = { athlete_id: athleteId };
  if (weekStart) params.week_start = weekStart;
  return client.request('get_athlete_details', params, 'GET');
}

export function getCoachPricing(client, coachId = null) {
  const params = coachId ? { coach_id: coachId } : {};
  return client.request('get_coach_pricing', params, 'GET');
}

export function updateCoachPricing(client, pricing, pricesOnRequest = false) {
  return client.request('update_coach_pricing', { pricing, prices_on_request: pricesOnRequest ? 1 : 0 }, 'POST');
}

export function getMyCoachProfile(client) {
  return client.request('get_my_coach_profile', {}, 'GET');
}

export function updateCoachProfile(client, data) {
  return client.request('update_coach_profile', data, 'POST');
}

export function getCoachGroups(client) {
  return client.request('get_coach_groups', {}, 'GET');
}

export function saveCoachGroup(client, data) {
  return client.request('save_coach_group', data, 'POST');
}

export function deleteCoachGroup(client, groupId) {
  return client.request('delete_coach_group', { group_id: groupId }, 'POST');
}

export function getGroupMembers(client, groupId) {
  return client.request('get_group_members', { group_id: groupId }, 'GET');
}

export function updateGroupMembers(client, groupId, userIds) {
  return client.request('update_group_members', { group_id: groupId, user_ids: userIds }, 'POST');
}

export function getAthleteGroups(client, userId) {
  return client.request('get_athlete_groups', { user_id: userId }, 'GET');
}

export function getCoachApplications(client, params = {}) {
  return client.request('admin_coach_applications', params, 'GET');
}

export function approveCoachApplication(client, applicationId) {
  return client.request('admin_approve_coach', { application_id: applicationId }, 'POST');
}

export function rejectCoachApplication(client, applicationId) {
  return client.request('admin_reject_coach', { application_id: applicationId }, 'POST');
}

async function getCsrf(client) {
  const res = await client.request('get_csrf_token', {}, 'GET');
  return res?.csrf_token ?? res?.data?.csrf_token ?? null;
}

export function listWorkoutTemplates(client) {
  return client.request('list_workout_templates', {}, 'GET');
}

export function listExerciseLibrary(client) {
  return client.request('list_exercise_library', {}, 'GET');
}

export function getCoachEvents(client, hoursBack = 48) {
  return client.request('coach_events', { hours_back: hoursBack }, 'GET');
}

export async function saveWorkoutTemplate(client, data) {
  const csrf_token = await getCsrf(client);
  return client.request('save_workout_template', { ...data, csrf_token }, 'POST');
}

export async function deleteWorkoutTemplate(client, templateId) {
  const csrf_token = await getCsrf(client);
  return client.request('delete_workout_template', { template_id: templateId, csrf_token }, 'POST');
}

/**
 * @param payload { template_id, athlete_ids: number[], date: 'Y-m-d', overwrite?: bool }
 * @returns { ok, conflicts?, assigned?, overwritten?, errors? }
 */
export async function bulkAssignTraining(client, payload) {
  const csrf_token = await getCsrf(client);
  return client.request('bulk_assign_training', { ...payload, csrf_token }, 'POST');
}
