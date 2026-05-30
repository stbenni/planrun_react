/**
 * Баннер над диалогом с AI: показывает, что AI реально умеет менять план.
 * Юзеры часто не знают про write-инструменты — баннер делает это явным.
 */

const CHIPS = ['✎ править', '↔ переносить', '✓ отмечать', '🔄 пересчитать'];

export default function CapabilitiesBanner() {
  return (
    <div className="chat-cap-banner">
      <span className="chat-cap-banner__title">★ AI МОЖЕТ ИЗМЕНЯТЬ ТВОЙ ПЛАН</span>
      <div className="chat-cap-banner__chips">
        {CHIPS.map((chip) => (
          <span key={chip} className="chat-cap-chip">{chip}</span>
        ))}
      </div>
    </div>
  );
}
