/**
 * ChatComposerInput — rich-поле ввода (contenteditable) с инлайновыми Apple-эмодзи.
 * Совместимо с существующей логикой отправки: через ref отдаёт .value (get/set),
 * как у обычного <input>, поэтому submit/clear в useChatSubmitHandlers не меняются.
 *  - get value: сериализует содержимое (текст + <img data-emoji> → native + <br> → \n)
 *  - set value: '' очищает; непустая строка ставится как текст
 *  - insertEmoji({native, unified}): вставляет <img> Apple-эмодзи в позицию курсора
 *  - Enter → отправка (requestSubmit ближайшей формы), Shift+Enter → перенос строки
 */

import { forwardRef, useImperativeHandle, useRef, useCallback } from 'react';
import { appleEmojiImageURL } from './emojiAssets';
import './ChatComposerInput.css';

function serialize(el) {
  if (!el) return '';
  let out = '';
  el.childNodes.forEach((node) => {
    if (node.nodeType === Node.TEXT_NODE) {
      out += node.nodeValue;
    } else if (node.nodeName === 'IMG') {
      out += node.getAttribute('data-emoji') || node.getAttribute('alt') || '';
    } else if (node.nodeName === 'BR') {
      out += '\n';
    } else if (node.nodeType === Node.ELEMENT_NODE) {
      // div/p, который браузер вставляет на Enter — трактуем как перенос
      if (out && !out.endsWith('\n')) out += '\n';
      out += serialize(node);
    }
  });
  return out;
}

function makeEmojiImg(native, unified) {
  const img = document.createElement('img');
  img.className = 'emoji-img';
  img.src = appleEmojiImageURL(unified);
  img.alt = native;
  img.setAttribute('data-emoji', native);
  img.setAttribute('draggable', 'false');
  return img;
}

function insertNodeAtCaret(el, node) {
  el.focus();
  const sel = window.getSelection();
  if (sel && sel.rangeCount && el.contains(sel.anchorNode)) {
    const range = sel.getRangeAt(0);
    range.deleteContents();
    range.insertNode(node);
    range.setStartAfter(node);
    range.setEndAfter(node);
    range.collapse(true);
    sel.removeAllRanges();
    sel.addRange(range);
  } else {
    el.appendChild(node);
    // курсор в конец
    const range = document.createRange();
    range.selectNodeContents(el);
    range.collapse(false);
    const s = window.getSelection();
    s.removeAllRanges();
    s.addRange(range);
  }
}

const ChatComposerInput = forwardRef(function ChatComposerInput(
  { onChange, onValueChange, placeholder, disabled, className = '', maxLength = 4000 },
  ref,
) {
  const elRef = useRef(null);

  const syncEmptyClass = useCallback((text) => {
    const el = elRef.current;
    if (!el) return;
    el.classList.toggle('is-empty', !text);
  }, []);

  const fireChange = useCallback(() => {
    const text = serialize(elRef.current);
    syncEmptyClass(text);
    onChange?.(text);
    onValueChange?.(text);
  }, [onChange, onValueChange, syncEmptyClass]);

  useImperativeHandle(ref, () => ({
    get value() { return serialize(elRef.current); },
    set value(v) {
      const el = elRef.current;
      if (!el) return;
      if (!v) el.innerHTML = '';
      else el.textContent = v;
      syncEmptyClass(v);
    },
    focus() { elRef.current?.focus(); },
    insertEmoji(emoji) {
      const el = elRef.current;
      if (!el || disabled) return;
      const native = typeof emoji === 'string' ? emoji : emoji?.native;
      const unified = typeof emoji === 'string' ? null : emoji?.unified;
      if (!native || !unified) return;
      if (serialize(el).length >= maxLength) return;
      insertNodeAtCaret(el, makeEmojiImg(native, unified));
      fireChange();
    },
    get el() { return elRef.current; },
  }), [disabled, fireChange, maxLength, syncEmptyClass]);

  const handleKeyDown = useCallback((e) => {
    // Не отправляем во время IME-композиции (важно для автодополнения/раскладок)
    if (e.isComposing || e.keyCode === 229) return;
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      const form = elRef.current?.closest('form');
      if (form?.requestSubmit) form.requestSubmit();
      else form?.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    }
  }, []);

  const handlePaste = useCallback((e) => {
    // Вставляем как простой текст (без чужого HTML)
    e.preventDefault();
    const text = e.clipboardData?.getData('text/plain') || '';
    if (text) {
      const sel = window.getSelection();
      if (sel && sel.rangeCount) {
        const range = sel.getRangeAt(0);
        range.deleteContents();
        range.insertNode(document.createTextNode(text));
        range.collapse(false);
        sel.removeAllRanges();
        sel.addRange(range);
      }
      fireChange();
    }
  }, [fireChange]);

  return (
    <div
      ref={elRef}
      className={`chat-input chat-composer-input is-empty ${className}`}
      contentEditable={!disabled}
      role="textbox"
      aria-multiline="true"
      aria-label={placeholder}
      data-placeholder={placeholder}
      onInput={fireChange}
      onKeyDown={handleKeyDown}
      onPaste={handlePaste}
      suppressContentEditableWarning
    />
  );
});

export default ChatComposerInput;
