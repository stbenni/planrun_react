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

export function getSiteSettings(client) {
  return client.request('get_site_settings', {}, 'GET');
}
