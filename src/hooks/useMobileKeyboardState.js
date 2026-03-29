import { useEffect, useRef, useState } from 'react';
import { Keyboard } from '@capacitor/keyboard';
import { isNativeCapacitor } from '../services/TokenStorageService';

const MOBILE_BREAKPOINT = 1023;
const DEFAULT_KEYBOARD_HEIGHT = 120;
const TEXT_INPUT_TYPES = new Set([
  'text',
  'search',
  'email',
  'url',
  'tel',
  'password',
  'number',
]);

const isTextEntryElement = (element) => {
  if (!element || typeof HTMLElement === 'undefined' || !(element instanceof HTMLElement)) {
    return false;
  }

  if (typeof HTMLTextAreaElement !== 'undefined' && element instanceof HTMLTextAreaElement) {
    return !element.disabled && !element.readOnly;
  }

  if (typeof HTMLInputElement !== 'undefined' && element instanceof HTMLInputElement) {
    const inputType = (element.type || 'text').toLowerCase();
    return TEXT_INPUT_TYPES.has(inputType) && !element.disabled && !element.readOnly;
  }

  return element.isContentEditable;
};

const getViewportMetrics = () => {
  const viewport = window.visualViewport;
  const layoutHeight = Math.max(0, Math.round(window.innerHeight));
  const visualHeight = Math.max(0, Math.round(viewport?.height ?? layoutHeight));
  const offsetTop = Math.max(0, Math.round(viewport?.offsetTop ?? 0));
  const visibleHeight = Math.max(0, Math.min(layoutHeight, visualHeight + offsetTop));

  return {
    viewportHeight: visibleHeight,
    visibleHeight,
    baselineHeight: Math.max(layoutHeight, visibleHeight, visualHeight),
  };
};

const useMobileKeyboardState = ({ enabled = true, minKeyboardHeight = DEFAULT_KEYBOARD_HEIGHT } = {}) => {
  const baselineViewportHeightRef = useRef(0);
  const nativeKeyboardOpenRef = useRef(false);
  const nativeKeyboardHeightRef = useRef(0);
  const [state, setState] = useState({
    isKeyboardOpen: false,
    viewportHeight: null,
  });

  useEffect(() => {
    if (!enabled) {
      baselineViewportHeightRef.current = 0;
      nativeKeyboardOpenRef.current = false;
      nativeKeyboardHeightRef.current = 0;
      setState((prevState) => (
        prevState.isKeyboardOpen || prevState.viewportHeight !== null
          ? { isKeyboardOpen: false, viewportHeight: null }
          : prevState
      ));
      return undefined;
    }

    if (typeof window === 'undefined' || typeof document === 'undefined') {
      return undefined;
    }

    const viewport = window.visualViewport;
    const isNativeApp = isNativeCapacitor();
    let frameId = 0;
    let focusTimeoutId = 0;
    const nativeListenerPromises = [];

    const updateState = () => {
      cancelAnimationFrame(frameId);
      frameId = window.requestAnimationFrame(() => {
        const activeElement = document.activeElement;
        const hasFocusedTextInput = isTextEntryElement(activeElement);
        const isSmallViewport = window.innerWidth <= MOBILE_BREAKPOINT;
        const { viewportHeight, visibleHeight, baselineHeight } = getViewportMetrics();

        if (!hasFocusedTextInput || !baselineViewportHeightRef.current) {
          baselineViewportHeightRef.current = baselineHeight;
        }

        const nativeKeyboardOpen = isNativeApp && nativeKeyboardOpenRef.current;
        const nativeViewportHeight = nativeKeyboardOpen
          ? Math.max(
            0,
            Math.min(
              visibleHeight,
              baselineViewportHeightRef.current - nativeKeyboardHeightRef.current,
            ),
          )
          : visibleHeight;
        const coveredHeight = Math.max(
          0,
          baselineViewportHeightRef.current - visibleHeight,
          isNativeApp ? nativeKeyboardHeightRef.current : 0,
        );

        const nextState = {
          isKeyboardOpen: Boolean(
            isSmallViewport
            && (
              nativeKeyboardOpen
              || (hasFocusedTextInput && coveredHeight >= minKeyboardHeight)
            )
          ),
          viewportHeight: isNativeApp
            ? (nativeViewportHeight || viewportHeight || null)
            : (viewportHeight || null),
        };

        setState((prevState) => (
          prevState.isKeyboardOpen === nextState.isKeyboardOpen
          && prevState.viewportHeight === nextState.viewportHeight
            ? prevState
            : nextState
        ));
      });
    };

    const handleFocusChange = () => {
      window.clearTimeout(focusTimeoutId);
      focusTimeoutId = window.setTimeout(updateState, 60);
    };

    updateState();

    if (isNativeApp) {
      nativeListenerPromises.push(
        Keyboard.addListener('keyboardDidShow', (event) => {
          nativeKeyboardOpenRef.current = true;
          nativeKeyboardHeightRef.current = Math.max(0, Math.round(event?.keyboardHeight || 0));
          updateState();
        }),
        Keyboard.addListener('keyboardDidHide', () => {
          nativeKeyboardOpenRef.current = false;
          nativeKeyboardHeightRef.current = 0;
          updateState();
        }),
      );
    }

    document.addEventListener('focusin', handleFocusChange);
    document.addEventListener('focusout', handleFocusChange);
    window.addEventListener('resize', updateState);
    window.addEventListener('orientationchange', updateState);
    viewport?.addEventListener('resize', updateState);
    viewport?.addEventListener('scroll', updateState);

    return () => {
      cancelAnimationFrame(frameId);
      window.clearTimeout(focusTimeoutId);
      nativeKeyboardOpenRef.current = false;
      nativeKeyboardHeightRef.current = 0;
      document.removeEventListener('focusin', handleFocusChange);
      document.removeEventListener('focusout', handleFocusChange);
      window.removeEventListener('resize', updateState);
      window.removeEventListener('orientationchange', updateState);
      viewport?.removeEventListener('resize', updateState);
      viewport?.removeEventListener('scroll', updateState);
      Promise.allSettled(nativeListenerPromises).then((results) => {
        results.forEach((result) => {
          if (result.status === 'fulfilled') {
            result.value.remove();
          }
        });
      });
    };
  }, [enabled, minKeyboardHeight]);

  return state;
};

export default useMobileKeyboardState;
