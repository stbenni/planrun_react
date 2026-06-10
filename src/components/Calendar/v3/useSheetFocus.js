/* useSheetFocus — доступность для v3-шторок/модалок:
   автофокус внутрь, trap Tab, возврат фокуса на предыдущий элемент при закрытии. */
import { useEffect } from 'react';

const SELECTOR = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

export default function useSheetFocus(ref, active) {
  useEffect(() => {
    if (!active || !ref?.current) return undefined;
    const node = ref.current;
    const prevActive = document.activeElement;
    const focusables = () => Array.from(node.querySelectorAll(SELECTOR))
      .filter((el) => !el.disabled && el.offsetParent !== null);

    const first = focusables()[0];
    if (first) first.focus();

    const onKey = (e) => {
      if (e.key !== 'Tab') return;
      const f = focusables();
      if (f.length === 0) return;
      const a = f[0];
      const z = f[f.length - 1];
      if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); }
      else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); }
    };
    node.addEventListener('keydown', onKey);
    return () => {
      node.removeEventListener('keydown', onKey);
      if (prevActive && typeof prevActive.focus === 'function') prevActive.focus();
    };
  }, [active, ref]);
}
