/* useIsMobile — matchMedia(max-width:640px) для адаптивных v3-видов календаря. */
import { useEffect, useState } from 'react';

const MOBILE_Q = '(max-width: 640px)';

export default function useIsMobile() {
  const [m, setM] = useState(() => (typeof window !== 'undefined' && window.matchMedia
    ? window.matchMedia(MOBILE_Q).matches : false));
  useEffect(() => {
    if (typeof window === 'undefined' || !window.matchMedia) return undefined;
    const mq = window.matchMedia(MOBILE_Q);
    const fn = () => setM(mq.matches);
    mq.addEventListener('change', fn);
    fn();
    return () => mq.removeEventListener('change', fn);
  }, []);
  return m;
}
