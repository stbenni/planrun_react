/**
 * WorkoutShareButton — кнопка «Поделиться» + композер карточки (фото + glass-оверлей).
 *
 * props:
 *  - workout   объект тренировки
 *  - date      дата дня (YYYY-MM-DD) — для имени файла
 *  - timeline  таймлайн тренировки (для маршрута/карты); может быть null
 *  - api       ApiClient (берётся из useAuthStore, если не передан)
 *  - className класс кнопки (по умолчанию calv3-cta-ghost)
 *  - label     текст кнопки
 *  - children  кастомное содержимое кнопки (например иконка)
 */
import { useState } from 'react';
import useAuthStore from '../../stores/useAuthStore';
import ShareComposer from '../Share/ShareComposer';

export default function WorkoutShareButton({
  workout,
  date,
  timeline = null,
  api: apiProp,
  className = 'calv3-cta-ghost',
  label = 'Поделиться',
  title,
  children,
}) {
  const storeApi = useAuthStore((s) => s.api);
  const api = apiProp || storeApi;
  const [open, setOpen] = useState(false);

  if (!workout || !date) return null;

  return (
    <>
      <button
        type="button"
        className={className}
        onClick={() => setOpen(true)}
        title={title || label}
        aria-label={title || label}
      >
        {children ?? label}
      </button>
      <ShareComposer
        open={open}
        onClose={() => setOpen(false)}
        api={api}
        date={date}
        workout={workout}
        timeline={timeline}
      />
    </>
  );
}
