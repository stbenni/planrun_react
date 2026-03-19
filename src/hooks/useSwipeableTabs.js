import { useEffect, useRef } from 'react';

const DEFAULT_IGNORE_SELECTOR = [
  'input',
  'textarea',
  'select',
  '[contenteditable="true"]',
  '[data-swipe-lock="true"]',
].join(', ');

const MIN_AXIS_LOCK_DISTANCE = 12;
const MIN_SWIPE_DISTANCE = 56;
const SWIPE_RATIO = 1.1;

function shouldIgnoreSwipeTarget(target, ignoreSelector) {
  return target instanceof Element && Boolean(ignoreSelector) && Boolean(target.closest(ignoreSelector));
}

export function useSwipeableTabs({
  containerRef,
  tabs,
  activeTab,
  onTabChange,
  enabled = true,
  ignoreSelector = DEFAULT_IGNORE_SELECTOR,
}) {
  const tabsRef = useRef(tabs);
  const activeTabRef = useRef(activeTab);
  const onTabChangeRef = useRef(onTabChange);

  useEffect(() => {
    tabsRef.current = tabs;
  }, [tabs]);

  useEffect(() => {
    activeTabRef.current = activeTab;
  }, [activeTab]);

  useEffect(() => {
    onTabChangeRef.current = onTabChange;
  }, [onTabChange]);

  useEffect(() => {
    if (!enabled || typeof window === 'undefined') return undefined;

    const container = containerRef.current;
    if (!container || tabs.length < 2) return undefined;

    let tracking = false;
    let isHorizontalSwipe = false;
    let startX = 0;
    let startY = 0;

    const resetSwipe = () => {
      tracking = false;
      isHorizontalSwipe = false;
      startX = 0;
      startY = 0;
    };

    const handleTouchStart = (event) => {
      if (event.touches.length !== 1 || shouldIgnoreSwipeTarget(event.target, ignoreSelector)) {
        resetSwipe();
        return;
      }

      tracking = true;
      isHorizontalSwipe = false;
      startX = event.touches[0].clientX;
      startY = event.touches[0].clientY;
    };

    const handleTouchMove = (event) => {
      if (!tracking || event.touches.length !== 1) return;

      const deltaX = event.touches[0].clientX - startX;
      const deltaY = event.touches[0].clientY - startY;

      if (!isHorizontalSwipe) {
        if (Math.abs(deltaX) < MIN_AXIS_LOCK_DISTANCE) return;

        if (Math.abs(deltaX) <= Math.abs(deltaY) * SWIPE_RATIO) {
          if (Math.abs(deltaY) >= MIN_AXIS_LOCK_DISTANCE) {
            resetSwipe();
          }
          return;
        }

        isHorizontalSwipe = true;
      }

      event.preventDefault();
    };

    const handleTouchEnd = (event) => {
      if (!tracking) return;

      const deltaX = event.changedTouches[0].clientX - startX;
      const deltaY = event.changedTouches[0].clientY - startY;
      const currentTabs = tabsRef.current;
      const currentIndex = currentTabs.indexOf(activeTabRef.current);

      if (
        isHorizontalSwipe
        && currentIndex !== -1
        && Math.abs(deltaX) >= MIN_SWIPE_DISTANCE
        && Math.abs(deltaX) > Math.abs(deltaY) * SWIPE_RATIO
      ) {
        const nextIndex = deltaX < 0 ? currentIndex + 1 : currentIndex - 1;
        const nextTab = currentTabs[nextIndex];

        if (nextTab && nextTab !== activeTabRef.current) {
          onTabChangeRef.current(nextTab);
        }
      }

      resetSwipe();
    };

    container.addEventListener('touchstart', handleTouchStart, { passive: true });
    container.addEventListener('touchmove', handleTouchMove, { passive: false });
    container.addEventListener('touchend', handleTouchEnd, { passive: true });
    container.addEventListener('touchcancel', resetSwipe, { passive: true });

    return () => {
      container.removeEventListener('touchstart', handleTouchStart);
      container.removeEventListener('touchmove', handleTouchMove);
      container.removeEventListener('touchend', handleTouchEnd);
      container.removeEventListener('touchcancel', resetSwipe);
    };
  }, [containerRef, enabled, ignoreSelector, tabs.length]);
}
