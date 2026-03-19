import ApiClient from './ApiClient';
import useAuthStore from '../stores/useAuthStore';

let fallbackAuthClient = null;

function getAuthClient() {
  const storeApi = useAuthStore.getState?.().api;
  if (storeApi) {
    return storeApi;
  }

  if (!fallbackAuthClient) {
    fallbackAuthClient = new ApiClient();
  }

  return fallbackAuthClient;
}

export default getAuthClient;
