export function getAdminUsers(client, params = {}) {
  const searchParams = new URLSearchParams();
  if (params.page != null) searchParams.set('page', params.page);
  if (params.per_page != null) searchParams.set('per_page', params.per_page);
  if (params.search != null && params.search !== '') searchParams.set('search', params.search);
  return client.request('admin_list_users', searchParams.toString() ? Object.fromEntries(searchParams) : {}, 'GET');
}

export function getAdminUser(client, userId) {
  return client.request('admin_get_user', { user_id: userId }, 'GET');
}

export function updateAdminUser(client, payload) {
  return client.request('admin_update_user', payload, 'POST');
}

export function deleteUser(client, payload) {
  return client.request('delete_user', payload, 'POST');
}

export function getAdminSettings(client) {
  return client.request('admin_get_settings', {}, 'GET');
}

export function updateAdminSettings(client, payload) {
  return client.request('admin_update_settings', payload, 'POST');
}

export function getAdminNotificationTemplates(client) {
  return client.request('admin_get_notification_templates', {}, 'GET');
}

export function updateAdminNotificationTemplate(client, payload) {
  return client.request('admin_update_notification_template', payload, 'POST');
}

export function resetAdminNotificationTemplate(client, payload) {
  return client.request('admin_reset_notification_template', payload, 'POST');
}

export function getSiteSettings(client) {
  return client.request('get_site_settings', {}, 'GET');
}

// PR6 / Phase D.1: AI plan generation observability — admin dashboard
export function getAiPlanMetrics(client, params = {}) {
  const query = {};
  if (params.hours != null) query.hours = params.hours;
  return client.request('admin_ai_plan_metrics', query, 'GET');
}

export function getAiPlanRecentEvents(client, params = {}) {
  const query = {};
  if (params.limit != null) query.limit = params.limit;
  if (params.user_id != null) query.user_id = params.user_id;
  if (params.cohort) query.cohort = params.cohort;
  if (params.status) query.status = params.status;
  if (params.since) query.since = params.since;
  return client.request('admin_ai_plan_events', query, 'GET');
}
