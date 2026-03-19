export function getPlan(client, userId = null, viewContext = null) {
  const params = userId ? { user_id: userId } : {};
  if (viewContext) Object.assign(params, client._viewParams(viewContext));
  return client.request('load', params, 'GET');
}

export function savePlan(client, planData) {
  return client.request('save', { plan: JSON.stringify(planData) }, 'POST');
}

export function regeneratePlan(client) {
  return client.request('regenerate_plan_with_progress', {}, 'POST');
}

export function recalculatePlan(client, reason = null) {
  const params = {};
  if (reason) params.reason = reason;
  return client.request('recalculate_plan', params, 'POST');
}

export function generateNextPlan(client, goals = null) {
  const params = {};
  if (goals) params.goals = goals;
  return client.request('generate_next_plan', params, 'POST');
}

export async function checkPlanStatus(client, userId = null) {
  const params = userId ? { user_id: userId } : {};
  return client.request('check_plan_status', params, 'GET');
}

export function clearPlan(client) {
  return client.request('clear_plan', {}, 'POST');
}
