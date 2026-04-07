import { useEffect, useRef, useState } from 'react';
import { Keyboard } from '@capacitor/keyboard';
import { isNativeCapacitor } from '../services/TokenStorageService';

const MOBILE_BREAKPOINT = 1023;
const DEFAULT_KEYBOARD_HEIGHT = 120;
const MODERN_WEBVIEW_KEYBOARD_INSETS_VERSION = 140;
// Some Android WebViews only report a partial resize while the host still handles
// most of the IME movement. A high threshold causes double compensation and huge gaps.
const NATIVE_RESIZE_KEYBOARD_THRESHOLD = 16;
const NATIVE_KEYBOARD_SIGNAL_THRESHOLD = 48;
const EMPTY_NATIVE_METRICS = Object.freeze({
  safeAreaBottom: 0,
  imeBottom: 0,
  viewportHeight: 0,
});
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

const readRootInset = (propertyName) => {
  if (typeof window === 'undefined' || typeof document === 'undefined') {
    return 0;
  }

  const rawValue = window.getComputedStyle(document.documentElement).getPropertyValue(propertyName).trim();
  const parsedValue = Number.parseFloat(rawValue);
  return Number.isFinite(parsedValue) ? Math.max(0, Math.round(parsedValue)) : 0;
};

const getVisualViewportHeight = () => {
  if (typeof window === 'undefined') {
    return 0;
  }

  const layoutHeight = Math.max(0, Math.round(window.innerHeight || 0));
  return Math.max(0, Math.round(window.visualViewport?.height ?? layoutHeight));
};

const getUserAgent = () => {
  if (typeof navigator === 'undefined') {
    return '';
  }

  return navigator.userAgent || '';
};

const parseChromiumMajorVersion = (userAgent) => {
  const match = userAgent.match(/Chrome\/(\d+)/i);
  const version = Number.parseInt(match?.[1] || '', 10);
  return Number.isFinite(version) ? version : 0;
};

const getDefaultKeyboardState = () => ({
  isKeyboardOpen: false,
  viewportHeight: null,
  keyboardInset: 0,
  bottomSafeAreaInset: null,
});

const isSameKeyboardState = (prevState, nextState) => (
  prevState.isKeyboardOpen === nextState.isKeyboardOpen
  && prevState.viewportHeight === nextState.viewportHeight
  && prevState.keyboardInset === nextState.keyboardInset
  && prevState.bottomSafeAreaInset === nextState.bottomSafeAreaInset
);

const readNativeMetricsFromRoot = () => ({
  safeAreaBottom: readRootInset('--native-safe-area-bottom'),
  imeBottom: readRootInset('--native-ime-inset-bottom'),
  viewportHeight: readRootInset('--native-layout-viewport-height'),
});

const useMobileKeyboardState = ({ enabled = true, minKeyboardHeight = DEFAULT_KEYBOARD_HEIGHT } = {}) => {
  const webBaselineViewportHeightRef = useRef(0);
  const nativeBaselineViewportHeightRef = useRef(0);
  const nativeBaselineBottomInsetRef = useRef(0);
  const nativeKeyboardOpenRef = useRef(false);
  const nativeKeyboardHeightRef = useRef(0);
  const nativeMetricsRef = useRef({ ...EMPTY_NATIVE_METRICS });
  const [state, setState] = useState(getDefaultKeyboardState);

  useEffect(() => {
    if (!enabled) {
      webBaselineViewportHeightRef.current = 0;
      nativeBaselineViewportHeightRef.current = 0;
      nativeBaselineBottomInsetRef.current = 0;
      nativeKeyboardOpenRef.current = false;
      nativeKeyboardHeightRef.current = 0;
      nativeMetricsRef.current = { ...EMPTY_NATIVE_METRICS };
      setState((prevState) => (
        !isSameKeyboardState(prevState, getDefaultKeyboardState())
          ? getDefaultKeyboardState()
          : prevState
      ));
      return undefined;
    }

    if (typeof window === 'undefined' || typeof document === 'undefined') {
      return undefined;
    }

    const isNativeApp = isNativeCapacitor();
    const chromiumMajorVersion = parseChromiumMajorVersion(getUserAgent());
    const hasModernWebViewInsets = chromiumMajorVersion >= MODERN_WEBVIEW_KEYBOARD_INSETS_VERSION;
    let frameId = 0;
    let focusTimeoutId = 0;
    const nativeListenerPromises = [];

    const syncNativeMetricsFromRoot = () => {
      nativeMetricsRef.current = readNativeMetricsFromRoot();
    };

    const syncState = (nextState) => {
      setState((prevState) => (
        isSameKeyboardState(prevState, nextState) ? prevState : nextState
      ));
    };

    if (isNativeApp) {
      syncNativeMetricsFromRoot();
      nativeBaselineBottomInsetRef.current = nativeMetricsRef.current.safeAreaBottom;
    }

    const updateState = () => {
      cancelAnimationFrame(frameId);
      frameId = window.requestAnimationFrame(() => {
        const hasFocusedTextInput = isTextEntryElement(document.activeElement);
        const isSmallViewport = window.innerWidth <= MOBILE_BREAKPOINT;

        if (!isNativeApp) {
          const currentViewportHeight = getVisualViewportHeight();

          if (!hasFocusedTextInput || !webBaselineViewportHeightRef.current) {
            webBaselineViewportHeightRef.current = currentViewportHeight;
          }

          const coveredHeight = Math.max(
            0,
            webBaselineViewportHeightRef.current - currentViewportHeight,
          );
          const isKeyboardOpen = Boolean(isSmallViewport && hasFocusedTextInput && coveredHeight >= minKeyboardHeight);
          const nextState = {
            isKeyboardOpen,
            viewportHeight: isKeyboardOpen ? currentViewportHeight : null,
            keyboardInset: 0,
            bottomSafeAreaInset: null,
          };

          syncState(nextState);
          return;
        }

        const { safeAreaBottom, imeBottom, viewportHeight } = nativeMetricsRef.current;
        const currentViewportHeight = Math.max(
          0,
          Math.round(viewportHeight || window.innerHeight || 0),
        );

        if (currentViewportHeight > 0 && (
          !nativeBaselineViewportHeightRef.current
          || (!nativeKeyboardOpenRef.current && imeBottom === 0)
        )) {
          nativeBaselineViewportHeightRef.current = currentViewportHeight;
        }

        if (safeAreaBottom > 0 && (
          !nativeBaselineBottomInsetRef.current
          || (!nativeKeyboardOpenRef.current && imeBottom === 0)
        )) {
          nativeBaselineBottomInsetRef.current = safeAreaBottom;
        }

        const stableBottomInset = nativeBaselineBottomInsetRef.current;
        const baselineViewportHeight = nativeBaselineViewportHeightRef.current || currentViewportHeight;
        const resizeDelta = Math.max(0, baselineViewportHeight - currentViewportHeight);
        const pluginKeyboardOcclusion = nativeKeyboardHeightRef.current > 0
          ? Math.max(0, nativeKeyboardHeightRef.current - stableBottomInset)
          : 0;
        const imeOcclusion = imeBottom > 0
          ? Math.max(0, imeBottom - stableBottomInset)
          : 0;
        const fallbackKeyboardOcclusion = nativeKeyboardOpenRef.current
          ? minKeyboardHeight
          : 0;
        const measuredKeyboardOcclusion = Math.max(
          pluginKeyboardOcclusion,
          imeOcclusion,
        );
        const keyboardOcclusion = Math.max(
          measuredKeyboardOcclusion,
          fallbackKeyboardOcclusion,
        );
        const resizeKeyboardDetected = resizeDelta >= NATIVE_RESIZE_KEYBOARD_THRESHOLD;
        const keyboardSignalDetected = (
          nativeKeyboardOpenRef.current
          || nativeKeyboardHeightRef.current >= NATIVE_KEYBOARD_SIGNAL_THRESHOLD
          || imeBottom >= NATIVE_KEYBOARD_SIGNAL_THRESHOLD
          || keyboardOcclusion >= minKeyboardHeight
          || resizeKeyboardDetected
        );
        // Legacy Android WebViews often need manual IME compensation.
        // Modern Chromium WebViews report enough native IME data that we can
        // trust the host resize path and avoid double-lifting the composer.
        const shouldTrustModernImeHandling = hasModernWebViewInsets && (
          imeBottom >= NATIVE_KEYBOARD_SIGNAL_THRESHOLD
          || nativeKeyboardHeightRef.current >= NATIVE_KEYBOARD_SIGNAL_THRESHOLD
        );
        const usesViewportResize = resizeKeyboardDetected || shouldTrustModernImeHandling;
        // Some newer Android WebViews shrink the WebView by keyboard height plus an extra
        // bottom inset. Compensate only for that overshoot instead of adding a full safe area.
        const viewportCompensation = usesViewportResize && measuredKeyboardOcclusion > 0
          ? Math.max(0, resizeDelta - measuredKeyboardOcclusion)
          : 0;
        const compensatedViewportHeight = Math.min(
          baselineViewportHeight,
          currentViewportHeight + viewportCompensation,
        );
        const keyboardInset = usesViewportResize
          ? 0
          : Math.max(0, keyboardOcclusion - resizeDelta);
        const isKeyboardOpen = Boolean(
          isSmallViewport
          && hasFocusedTextInput
          && keyboardSignalDetected
        );
        const nextState = {
          isKeyboardOpen,
          viewportHeight: compensatedViewportHeight || null,
          keyboardInset: isKeyboardOpen ? keyboardInset : 0,
          bottomSafeAreaInset: isKeyboardOpen
            ? (usesViewportResize ? 0 : stableBottomInset)
            : (stableBottomInset || null),
        };

        syncState(nextState);
      });
    };

    const handleFocusChange = () => {
      window.clearTimeout(focusTimeoutId);
      focusTimeoutId = window.setTimeout(updateState, 60);
    };

    const handleNativeInsetsChange = () => {
      syncNativeMetricsFromRoot();
      updateState();
    };

    updateState();

    if (isNativeApp) {
      nativeListenerPromises.push(
        Keyboard.addListener('keyboardWillShow', (event) => {
          nativeKeyboardOpenRef.current = true;
          nativeKeyboardHeightRef.current = Math.max(0, Math.round(event?.keyboardHeight || 0));
          updateState();
        }),
        Keyboard.addListener('keyboardDidShow', (event) => {
          nativeKeyboardOpenRef.current = true;
          nativeKeyboardHeightRef.current = Math.max(0, Math.round(event?.keyboardHeight || 0));
          updateState();
        }),
        Keyboard.addListener('keyboardWillHide', () => {
          nativeKeyboardOpenRef.current = false;
          nativeKeyboardHeightRef.current = 0;
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
    window.addEventListener('planrun:native-insets', handleNativeInsetsChange);

    if (!isNativeApp) {
      window.visualViewport?.addEventListener('resize', updateState);
    }

    return () => {
      cancelAnimationFrame(frameId);
      window.clearTimeout(focusTimeoutId);
      nativeKeyboardOpenRef.current = false;
      nativeKeyboardHeightRef.current = 0;
      document.removeEventListener('focusin', handleFocusChange);
      document.removeEventListener('focusout', handleFocusChange);
      window.removeEventListener('resize', updateState);
      window.removeEventListener('orientationchange', updateState);
      window.removeEventListener('planrun:native-insets', handleNativeInsetsChange);
      if (!isNativeApp) {
        window.visualViewport?.removeEventListener('resize', updateState);
      }
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
