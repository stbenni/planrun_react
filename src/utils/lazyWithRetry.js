import React from 'react';

const RETRY_PREFIX = 'planrun:lazy-retry:';
const CHUNK_ERROR_PATTERNS = [
  /ChunkLoadError/i,
  /Loading chunk [\w-]+ failed/i,
  /Failed to fetch dynamically imported module/i,
  /Importing a module script failed/i,
  /error loading dynamically imported module/i,
];

export function isChunkLoadError(error) {
  const message = String(error?.message || error || '');
  return CHUNK_ERROR_PATTERNS.some((pattern) => pattern.test(message));
}

export function lazyWithRetry(importer, retryKey = 'module') {
  return React.lazy(async () => {
    try {
      const module = await importer();
      if (typeof window !== 'undefined' && window.sessionStorage) {
        window.sessionStorage.removeItem(`${RETRY_PREFIX}${retryKey}`);
      }
      return module;
    } catch (error) {
      if (typeof window !== 'undefined' && window.sessionStorage && isChunkLoadError(error)) {
        const storageKey = `${RETRY_PREFIX}${retryKey}`;
        const alreadyRetried = window.sessionStorage.getItem(storageKey) === '1';

        if (!alreadyRetried) {
          window.sessionStorage.setItem(storageKey, '1');
          window.location.reload();
          return new Promise(() => {});
        }

        window.sessionStorage.removeItem(storageKey);
      }

      throw error;
    }
  });
}

export default lazyWithRetry;
