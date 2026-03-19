import { useCallback, useEffect, useRef, useState } from 'react';

export function useRetryCooldown() {
  const [secondsLeft, setSecondsLeft] = useState(0);
  const intervalRef = useRef(null);

  const clearCooldown = useCallback(() => {
    if (intervalRef.current) {
      clearInterval(intervalRef.current);
      intervalRef.current = null;
    }
    setSecondsLeft(0);
  }, []);

  const startCooldown = useCallback((seconds) => {
    const normalized = Math.max(0, Math.ceil(Number(seconds) || 0));
    if (normalized <= 0) {
      clearCooldown();
      return;
    }

    if (intervalRef.current) {
      clearInterval(intervalRef.current);
      intervalRef.current = null;
    }

    setSecondsLeft(normalized);
    intervalRef.current = setInterval(() => {
      setSecondsLeft((current) => {
        if (current <= 1) {
          if (intervalRef.current) {
            clearInterval(intervalRef.current);
            intervalRef.current = null;
          }
          return 0;
        }
        return current - 1;
      });
    }, 1000);
  }, [clearCooldown]);

  useEffect(() => clearCooldown, [clearCooldown]);

  return {
    secondsLeft,
    isCoolingDown: secondsLeft > 0,
    startCooldown,
    clearCooldown,
  };
}
