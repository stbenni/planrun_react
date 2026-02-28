/**
 * Ввод PIN-кода (4 цифры).
 * Скрытый input + визуальные точки.
 * При showKeypad — экранная цифровая клавиатура, без вызова нативной.
 */

import React, { useRef, useEffect, useState } from 'react';
import './PinInput.css';

const KEYPAD_LAYOUT = [
  ['1', '2', '3'],
  ['4', '5', '6'],
  ['7', '8', '9'],
  ['', '0', 'back'],
];

const PinInput = ({ length = 4, value = '', onChange, onComplete, disabled, error, placeholder = '••••', autoFocus = true, showKeypad = false, keypadExtra }) => {
  const inputRef = useRef(null);
  const [localValue, setLocalValue] = useState(value);

  const len = 4;

  useEffect(() => {
    setLocalValue(value);
  }, [value]);

  useEffect(() => {
    if (!showKeypad && autoFocus && inputRef.current) {
      inputRef.current.focus();
    }
  }, [autoFocus, showKeypad]);

  const handleChange = (e) => {
    const v = e.target.value.replace(/\D/g, '').slice(0, len);
    setLocalValue(v);
    onChange?.(v);
    if (v.length === len) {
      onComplete?.(v);
    }
  };

  const handleKeyDown = (e) => {
    if (e.key === 'Backspace' && localValue.length === 0) {
      e.preventDefault();
    }
  };

  const handleKeypadDigit = (digit) => {
    if (disabled || localValue.length >= len) return;
    const v = localValue + digit;
    setLocalValue(v);
    onChange?.(v);
    if (v.length === len) {
      onComplete?.(v);
    }
  };

  const handleKeypadBackspace = () => {
    if (disabled || localValue.length === 0) return;
    const v = localValue.slice(0, -1);
    setLocalValue(v);
    onChange?.(v);
  };

  return (
    <div className={`pin-input ${error ? 'pin-input--error' : ''} ${showKeypad ? 'pin-input--keypad' : ''}`}>
      <input
        ref={inputRef}
        type="password"
        inputMode={showKeypad ? 'none' : 'numeric'}
        pattern="[0-9]*"
        autoComplete="off"
        maxLength={len}
        value={localValue}
        onChange={handleChange}
        onKeyDown={handleKeyDown}
        disabled={disabled}
        readOnly={showKeypad}
        tabIndex={showKeypad ? -1 : 0}
        className="pin-input__field"
        aria-label="PIN-код"
      />
      <div
        className="pin-input__dots"
        onClick={showKeypad ? undefined : () => inputRef.current?.focus()}
        role={showKeypad ? 'presentation' : 'button'}
      >
        {Array.from({ length: len }).map((_, i) => (
          <span
            key={i}
            className={`pin-input__dot ${i < localValue.length ? 'pin-input__dot--filled' : ''}`}
          />
        ))}
      </div>
      {showKeypad && (
        <div className="pin-input__keypad">
          {KEYPAD_LAYOUT.flat().map((cell, idx) =>
            cell === 'back' ? (
              <button
                key={`cell-${idx}`}
                type="button"
                className="pin-input__keypad-btn pin-input__keypad-btn--back"
                onClick={handleKeypadBackspace}
                disabled={disabled || localValue.length === 0}
                aria-label="Удалить"
              >
                ⌫
              </button>
            ) : cell === '' ? (
              <span key={`cell-${idx}`} className="pin-input__keypad-slot">
                {keypadExtra || <span className="pin-input__keypad-spacer" aria-hidden />}
              </span>
            ) : (
              <button
                key={`cell-${idx}`}
                type="button"
                className="pin-input__keypad-btn"
                onClick={() => handleKeypadDigit(cell)}
                disabled={disabled}
                aria-label={`Цифра ${cell}`}
              >
                {cell}
              </button>
            )
          )}
        </div>
      )}
      {error && <p className="pin-input__error">{error}</p>}
    </div>
  );
};

export default PinInput;
