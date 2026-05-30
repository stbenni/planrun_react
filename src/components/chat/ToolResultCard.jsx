/**
 * Зелёная карточка-результат под сообщением AI, когда в этом ответе сработал
 * write-инструмент (правка плана). Источник данных — metadata.tools_used
 * сообщения (персистится бэкендом) или собранный во время стрима список.
 */

const WRITE_TOOL_LABELS = {
  update_training_day: 'Тренировка обновлена',
  add_training_day: 'Тренировка добавлена',
  delete_training_day: 'Тренировка удалена',
  swap_training_days: 'Дни переставлены местами',
  move_training_day: 'Тренировка перенесена',
  copy_day: 'Тренировка скопирована',
  log_workout: 'Результат записан',
  recalculate_plan: 'Пересчёт плана запущен',
  generate_next_plan: 'Генерация нового плана запущена',
};

export default function ToolResultCard({ tools, onOpen }) {
  const writeTools = (Array.isArray(tools) ? tools : []).filter((t) => WRITE_TOOL_LABELS[t]);
  if (writeTools.length === 0) return null;

  const distinct = [...new Set(writeTools)];
  const title = distinct.length === 1 ? WRITE_TOOL_LABELS[distinct[0]] : 'План обновлён';
  const isAsync = distinct.length === 1 && (distinct[0] === 'recalculate_plan' || distinct[0] === 'generate_next_plan');
  const detail = isAsync ? 'Обнови календарь через 3–5 минут' : 'Изменения сохранены в плане';

  return (
    <div className="chat-tool-result" role="status">
      <span className="chat-tool-result__icon" aria-hidden="true">✓</span>
      <div className="chat-tool-result__body">
        <div className="chat-tool-result__title">{title}</div>
        <div className="chat-tool-result__detail">{detail}</div>
      </div>
      {onOpen && (
        <button type="button" className="chat-tool-result__btn" onClick={onOpen}>
          Открыть
        </button>
      )}
    </div>
  );
}
