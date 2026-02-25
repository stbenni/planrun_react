import { useLocation } from 'react-router-dom';

export function useIsTabActive(path) {
  const { pathname } = useLocation();
  if (path === '/') return pathname === '/' || pathname === '/dashboard';
  return pathname.startsWith(path);
}
