import { useCallback, useState } from 'react';

export function useCoachPricing(api, setMessage) {
  const [coachPricing, setCoachPricing] = useState([]);
  const [coachPricingLoading, setCoachPricingLoading] = useState(false);
  const [savingPricing, setSavingPricing] = useState(false);

  const loadCoachPricing = useCallback(async () => {
    if (!api) return;
    setCoachPricingLoading(true);
    try {
      const res = await api.getCoachPricing();
      const data = res?.data ?? res;
      setCoachPricing(Array.isArray(data?.pricing) ? data.pricing : []);
    } catch (error) {
      void error;
    } finally {
      setCoachPricingLoading(false);
    }
  }, [api]);

  const handleAddPricingItem = useCallback(() => {
    setCoachPricing((prev) => [...prev, { id: `new_${Date.now()}`, type: 'individual', label: '', price: '', currency: 'RUB', period: 'month' }]);
  }, []);

  const handlePricingChange = useCallback((idx, field, value) => {
    setCoachPricing((prev) => prev.map((item, index) => index === idx ? { ...item, [field]: value } : item));
  }, []);

  const handleRemovePricingItem = useCallback((idx) => {
    setCoachPricing((prev) => prev.filter((_, index) => index !== idx));
  }, []);

  const handleSavePricing = useCallback(async () => {
    if (!api) return;
    setSavingPricing(true);
    try {
      await api.updateCoachPricing(coachPricing.map((item) => ({
        type: item.type,
        label: item.label,
        price: item.price || null,
        currency: item.currency,
        period: item.period,
      })));
      setMessage({ type: 'success', text: 'Стоимость сохранена' });
      setTimeout(() => setMessage({ type: '', text: '' }), 3000);
    } catch (error) {
      setMessage({ type: 'error', text: error.message || 'Ошибка сохранения' });
    } finally {
      setSavingPricing(false);
    }
  }, [api, coachPricing, setMessage]);

  return {
    coachPricing,
    coachPricingLoading,
    savingPricing,
    loadCoachPricing,
    handleAddPricingItem,
    handlePricingChange,
    handleRemovePricingItem,
    handleSavePricing,
  };
}
