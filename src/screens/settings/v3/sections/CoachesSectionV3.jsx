import { Group, Row, NavRow } from '../primitives';
import { getDisplayName } from '../../../../utils/displayName';

export default function CoachesSectionV3({ ctx }) {
  const {
    myCoaches, myCoachesLoading, removingCoachId, onRemoveCoach, onFindTrainer,
    isCoachRole, coachPricing, coachPricingLoading, savingPricing, onEditCoachPage,
    onAddPricing, onPricingChange, onRemovePricing, onSavePricing,
  } = ctx;

  return (
    <>
      <Group label="Мой тренер"
        footer="План ведёт тренер. Сменить или вернуться к AI — в разделе «Тренировки».">
        {myCoachesLoading ? (
          <Row><span className="sv3-row-sub">Загрузка…</span></Row>
        ) : myCoaches.length === 0 ? (
          <Row><span className="sv3-row-sub">У вас пока нет тренеров</span></Row>
        ) : (
          myCoaches.map((coach) => (
            <Row key={coach.id} className="sv3-id-row">
              <div className="sv3-avatar sv3-avatar--sm">{(getDisplayName(coach) || '?').slice(0, 1)}</div>
              <div className="sv3-row-main" style={{ marginLeft: 12 }}>
                <div className="sv3-coach-name">{getDisplayName(coach)}</div>
                <div className="sv3-coach-status">● Активный тренер</div>
              </div>
              <button type="button" className="sv3-link-btn" disabled={removingCoachId === coach.id}
                onClick={() => onRemoveCoach(coach.id)}>
                {removingCoachId === coach.id ? '…' : 'Отвязать'}
              </button>
            </Row>
          ))
        )}
      </Group>

      <Group label="Заявки">
        <NavRow title="Найти тренера" onClick={onFindTrainer} />
      </Group>

      {isCoachRole && onEditCoachPage && (
        <Group label="Моя страница тренера" footer="Описание, специализация, тарифы — то, что видят атлеты при выборе тренера">
          <NavRow title="Редактировать страницу" onClick={onEditCoachPage} />
        </Group>
      )}

      {isCoachRole && (
        <Group label="Стоимость услуг" footer="Тарифы, которые видят ваши ученики">
          {coachPricingLoading ? (
            <Row><span className="sv3-row-sub">Загрузка…</span></Row>
          ) : (
            <>
              {coachPricing.map((item, idx) => (
                <Row key={item.id || idx} className="sv3-row--col">
                  <div style={{ display: 'flex', gap: 8 }}>
                    <select className="sv3-select" style={{ textAlign: 'left', flex: 1 }} value={item.type}
                      onChange={(e) => onPricingChange(idx, 'type', e.target.value)}>
                      <option value="individual">Индивидуально</option>
                      <option value="group">Группа</option>
                      <option value="consultation">Консультация</option>
                      <option value="custom">Другое</option>
                    </select>
                    <input className="sv3-input" style={{ textAlign: 'left', flex: 1 }} placeholder="Название"
                      value={item.label} onChange={(e) => onPricingChange(idx, 'label', e.target.value)} />
                  </div>
                  <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                    <input type="number" className="sv3-input--sm" placeholder="Цена" value={item.price || ''}
                      onChange={(e) => onPricingChange(idx, 'price', e.target.value)} />
                    <select className="sv3-select" style={{ textAlign: 'left', flex: 1 }} value={item.period}
                      onChange={(e) => onPricingChange(idx, 'period', e.target.value)}>
                      <option value="month">В месяц</option>
                      <option value="week">В неделю</option>
                      <option value="one_time">Разово</option>
                      <option value="custom">Другое</option>
                    </select>
                    <button type="button" className="sv3-link-btn" onClick={() => onRemovePricing(idx)} title="Удалить">✕</button>
                  </div>
                </Row>
              ))}
              <Row>
                <button type="button" className="sv3-ghost-btn" onClick={onAddPricing}>+ Добавить тариф</button>
                <div className="sv3-spacer" />
                {coachPricing.length > 0 && (
                  <button type="button" className="sv3-connect-btn" disabled={savingPricing} onClick={onSavePricing}>
                    {savingPricing ? '…' : 'Сохранить'}
                  </button>
                )}
              </Row>
            </>
          )}
        </Group>
      )}
    </>
  );
}
