import { Capacitor } from '@capacitor/core';
import { forwardRef, memo, useCallback, useEffect, useImperativeHandle, useMemo, useRef, useState } from 'react';
import { SendIcon, StopIcon } from '../../components/common/Icons';
import { isNativeCapacitor } from '../../services/TokenStorageService';

const parseAndroidMajorVersion = () => {
  if (typeof navigator === 'undefined') {
    return 0;
  }

  const match = (navigator.userAgent || '').match(/Android\s+(\d+)/i);
  const version = Number.parseInt(match?.[1] || '', 10);
  return Number.isFinite(version) ? version : 0;
};

const getNativeComposerBridge = () => {
  if (typeof window === 'undefined') {
    return null;
  }

  return window.PlanRunNativeChatComposer ?? null;
};

const getDocumentTheme = () => {
  if (typeof document === 'undefined') {
    if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
      return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    return 'light';
  }

  const bodyTheme = document.body?.dataset?.theme;
  const rootTheme = document.documentElement?.dataset?.theme;
  const explicitTheme = bodyTheme || rootTheme;

  if (explicitTheme === 'dark' || explicitTheme === 'light') {
    return explicitTheme;
  }

  if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  return 'light';
};

const getNativeComposerStyleTokens = (styleSource) => {
  if (typeof window === 'undefined' || !styleSource) {
    return null;
  }

  const computedStyle = window.getComputedStyle(styleSource);
  const readToken = (name, fallback = '') => computedStyle.getPropertyValue(name).trim() || fallback;

  return {
    shellTop: readToken('--chat-composer-native-shell-top', '#FAFCFF'),
    shellBottom: readToken('--chat-composer-native-shell-bottom', '#F3F7FB'),
    shellBorder: readToken('--chat-composer-native-shell-border', '#E2E8F0'),
    inputTop: readToken('--chat-composer-native-input-top', '#FFFFFF'),
    inputBottom: readToken('--chat-composer-native-input-bottom', '#F7FAFC'),
    inputBorder: readToken('--chat-composer-native-input-border', '#FC4C02'),
    inputText: readToken('--chat-composer-native-text', readToken('--text-primary', '#0F172A')),
    inputPlaceholder: readToken('--chat-composer-native-placeholder', readToken('--text-tertiary', '#64748B')),
    buttonTop: readToken('--chat-composer-native-button-top', '#FC4C02'),
    buttonBottom: readToken('--chat-composer-native-button-bottom', '#E03D00'),
    buttonText: readToken('--chat-composer-native-button-text', '#FFFFFF'),
  };
};

const ChatComposer = memo(forwardRef(function ChatComposer({
  placeholder = 'Напишите сообщение...',
  disabled = false,
  submitting = false,
  showStopButton = false,
  visible = true,
  onStop,
  onSubmitText,
}, ref) {
  const [draft, setDraft] = useState('');
  const [theme, setTheme] = useState(() => getDocumentTheme());
  const inputRef = useRef(null);
  const nativeBridgePlaceholderRef = useRef(null);
  const disabledRef = useRef(disabled);
  const showStopButtonRef = useRef(showStopButton);
  const onStopRef = useRef(onStop);
  const onSubmitTextRef = useRef(onSubmitText);
  const isNativeAndroidComposer = useMemo(() => {
    try {
      return isNativeCapacitor() && Capacitor.getPlatform?.() === 'android';
    } catch {
      return false;
    }
  }, []);
  const draftRef = useRef('');
  const useNativeBridgeComposer = useMemo(() => {
    if (!isNativeAndroidComposer || parseAndroidMajorVersion() < 13) {
      return false;
    }

    const bridge = getNativeComposerBridge();
    return Boolean(bridge && typeof bridge.setComposerConfig === 'function');
  }, [isNativeAndroidComposer]);
  const hasText = draft.trim().length > 0;

  const syncDraftState = useCallback((nextValue) => {
    const normalizedValue = typeof nextValue === 'string' ? nextValue : '';
    draftRef.current = normalizedValue;
    setDraft(normalizedValue);
    return normalizedValue;
  }, []);

  const callNativeComposer = useCallback((method, ...args) => {
    const bridge = getNativeComposerBridge();
    if (!bridge || typeof bridge[method] !== 'function') {
      return false;
    }

    try {
      bridge[method](...args);
      return true;
    } catch {
      return false;
    }
  }, []);

  const setDraftValue = useCallback((nextValue, { moveCaretToEnd = false, focus = false } = {}) => {
    const normalizedValue = syncDraftState(nextValue);

    if (useNativeBridgeComposer) {
      callNativeComposer('setComposerText', normalizedValue, moveCaretToEnd);
      if (focus) {
        callNativeComposer('focusComposer');
      }
      return;
    }

    if (!moveCaretToEnd && !focus) return;

    requestAnimationFrame(() => {
      const inputEl = inputRef.current;
      if (!inputEl) return;
      if (focus) {
        inputEl.focus();
      }
      if (moveCaretToEnd && typeof inputEl.setSelectionRange === 'function') {
        const caretPosition = normalizedValue.length;
        inputEl.setSelectionRange(caretPosition, caretPosition);
      }
    });
  }, [callNativeComposer, syncDraftState, useNativeBridgeComposer]);

  const clearDraft = useCallback(() => {
    syncDraftState('');
    if (useNativeBridgeComposer) {
      callNativeComposer('clearComposer');
    }
  }, [callNativeComposer, syncDraftState, useNativeBridgeComposer]);

  useImperativeHandle(ref, () => ({
    clear: clearDraft,
    focus: () => {
      if (useNativeBridgeComposer) {
        callNativeComposer('focusComposer');
        return;
      }
      inputRef.current?.focus();
    },
    getValue: () => draftRef.current,
    setValue: setDraftValue,
  }), [callNativeComposer, clearDraft, setDraftValue, useNativeBridgeComposer]);

  useEffect(() => {
    draftRef.current = draft;
  }, [draft]);

  useEffect(() => {
    disabledRef.current = disabled;
    showStopButtonRef.current = showStopButton;
    onStopRef.current = onStop;
    onSubmitTextRef.current = onSubmitText;
  }, [disabled, onStop, onSubmitText, showStopButton]);

  useEffect(() => {
    if (!useNativeBridgeComposer) {
      return undefined;
    }

    const handleNativeSubmit = (event) => {
      if (disabledRef.current) return;

      const content = String(event.detail?.text || '').trim();
      if (!content) return;

      syncDraftState(content);
      onSubmitTextRef.current?.(content);
    };

    const handleNativeStop = () => {
      if (showStopButtonRef.current) {
        onStopRef.current?.();
      }
    };

    window.addEventListener('planrun:native-chat-submit', handleNativeSubmit);
    window.addEventListener('planrun:native-chat-stop', handleNativeStop);

    return () => {
      window.removeEventListener('planrun:native-chat-submit', handleNativeSubmit);
      window.removeEventListener('planrun:native-chat-stop', handleNativeStop);
    };
  }, [syncDraftState, useNativeBridgeComposer]);

  useEffect(() => {
    if (!useNativeBridgeComposer) {
      return undefined;
    }

    const root = typeof document === 'undefined' ? null : document.documentElement;
    const body = typeof document === 'undefined' ? null : document.body;
    if ((!root && !body) || typeof MutationObserver === 'undefined') {
      return undefined;
    }

    const syncTheme = () => {
      setTheme((prevTheme) => {
        const nextTheme = getDocumentTheme();
        return prevTheme === nextTheme ? prevTheme : nextTheme;
      });
    };

    syncTheme();
    const observer = new MutationObserver(syncTheme);
    const observeTarget = (target) => {
      if (!target) return;
      observer.observe(target, {
        attributes: true,
        attributeFilter: ['data-theme'],
      });
    };

    observeTarget(root);
    observeTarget(body);
    window.addEventListener('focus', syncTheme);
    document.addEventListener('visibilitychange', syncTheme);

    return () => {
      window.removeEventListener('focus', syncTheme);
      document.removeEventListener('visibilitychange', syncTheme);
      observer.disconnect();
    };
  }, [useNativeBridgeComposer]);

  useEffect(() => {
    if (!useNativeBridgeComposer) {
      return undefined;
    }

    return () => {
      callNativeComposer('hideComposer');
    };
  }, [callNativeComposer, useNativeBridgeComposer]);

  useEffect(() => {
    if (!useNativeBridgeComposer) {
      return;
    }

    const styleSource = nativeBridgePlaceholderRef.current
      ?? document.querySelector('.container.chat-page')
      ?? document.documentElement;

    callNativeComposer('setComposerConfig', JSON.stringify({
      visible,
      placeholder,
      disabled,
      submitting,
      showStopButton,
      theme,
      styles: getNativeComposerStyleTokens(styleSource),
    }));
  }, [callNativeComposer, disabled, placeholder, showStopButton, submitting, theme, useNativeBridgeComposer, visible]);

  const handleChange = useCallback((event) => {
    syncDraftState(event.currentTarget.value);
  }, [syncDraftState]);

  const handleSubmit = useCallback((event) => {
    event.preventDefault();
    if (disabled) return;

    const content = draftRef.current.trim();
    if (!content) return;

    onSubmitText?.(content);
  }, [disabled, onSubmitText]);

  const submitDraft = useCallback(() => {
    if (disabled) return;
    const content = draftRef.current.trim();
    if (!content) return;
    onSubmitText?.(content);
  }, [disabled, onSubmitText]);

  const handleTextareaKeyDown = useCallback((event) => {
    if (event.key !== 'Enter' || event.shiftKey || event.nativeEvent?.isComposing) return;
    event.preventDefault();
    submitDraft();
  }, [submitDraft]);

  const commonFieldProps = {
    ref: inputRef,
    className: 'chat-input',
    placeholder,
    name: 'chat_message',
    disabled,
    maxLength: 4000,
    autoComplete: 'off',
    spellCheck: false,
    autoCorrect: 'off',
    autoCapitalize: 'none',
    'aria-autocomplete': 'none',
    'data-gramm': 'false',
    'data-gramm_editor': 'false',
    'data-enable-grammarly': 'false',
    'data-lt-active': 'false',
    enterKeyHint: 'send',
  };

  if (useNativeBridgeComposer) {
    if (!visible) {
      return null;
    }

    return (
      <div ref={nativeBridgePlaceholderRef} className="chat-input-form chat-input-form--native-bridge" aria-hidden="true">
        <div className="chat-input chat-input--native-placeholder" />
        <div className="chat-send-btn chat-send-btn--native-placeholder" />
      </div>
    );
  }

  return (
    <form className="chat-input-form" onSubmit={handleSubmit}>
      {isNativeAndroidComposer ? (
        <textarea
          {...commonFieldProps}
          value={draft}
          rows={1}
          onChange={handleChange}
          onKeyDown={handleTextareaKeyDown}
        />
      ) : (
        <input
          {...commonFieldProps}
          type="text"
          value={draft}
          onChange={handleChange}
        />
      )}
      {showStopButton ? (
        <button type="button" className="chat-send-btn chat-stop-btn" onClick={onStop} title="Остановить">
          <StopIcon size={18} />
        </button>
      ) : (
        <button type="submit" className="chat-send-btn" disabled={disabled || !hasText} title={submitting ? 'Отправка…' : 'Отправить'}>
          {submitting ? <span className="chat-typing-dots chat-typing-dots--small"><span /><span /><span /></span> : <SendIcon size={18} />}
        </button>
      )}
    </form>
  );
}));

export default ChatComposer;
