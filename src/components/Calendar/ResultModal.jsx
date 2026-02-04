/**
 * Модальное окно ввода результата тренировки
 */

import React, { useState, useEffect } from 'react';
import Modal from '../common/Modal';

const ResultModal = ({ isOpen, onClose, date, weekNumber, dayKey, api, onSave }) => {
  const [inputMethod, setInputMethod] = useState(null); // 'manual' или 'file'
  const [formData, setFormData] = useState({
    distance: '',
    time: '',
    heartRate: '',
    notes: ''
  });
  const [file, setFile] = useState(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (isOpen) {
      loadExistingResult();
    } else {
      // Сброс при закрытии
      setInputMethod(null);
      setFormData({ distance: '', time: '', heartRate: '', notes: '' });
      setFile(null);
    }
  }, [isOpen, date, weekNumber, dayKey]);

  const loadExistingResult = async () => {
    try {
      const result = await api.getResult(date, weekNumber, dayKey);
      if (result) {
        setFormData({
          distance: result.result_distance || '',
          time: result.result_time || '',
          heartRate: result.avg_heart_rate || '',
          notes: result.notes || ''
        });
      }
    } catch (error) {
      // Результата нет - это нормально
    }
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);

    try {
      if (inputMethod === 'file' && file) {
        // Загрузка файла
        await api.uploadWorkout(file, { date });
        alert('Тренировка успешно загружена!');
        onClose();
        if (onSave) onSave();
      } else {
        // Сохранение вручную
        await api.saveResult({
          date,
          week: weekNumber,
          day: dayKey,
          result_distance: formData.distance ? parseFloat(formData.distance) : null,
          result_time: formData.time || null,
          avg_heart_rate: formData.heartRate ? parseInt(formData.heartRate) : null,
          notes: formData.notes || null,
          is_successful: true,
        });
        alert('Результат сохранен!');
        onClose();
        if (onSave) onSave();
      }
    } catch (error) {
      alert('Ошибка сохранения: ' + (error.message || 'Неизвестная ошибка'));
    } finally {
      setLoading(false);
    }
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Отметить тренировку"
      size="medium"
    >
      {!inputMethod ? (
        <div>
          <div className="form-group">
            <label>Способ ввода данных</label>
            <div style={{ display: 'flex', gap: '10px', marginTop: '10px' }}>
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => setInputMethod('manual')}
                style={{ flex: 1 }}
              >
                ✏️ Вручную
              </button>
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => setInputMethod('file')}
                style={{ flex: 1 }}
              >
                📤 Загрузить файл
              </button>
            </div>
          </div>
        </div>
      ) : inputMethod === 'manual' ? (
        <form onSubmit={handleSubmit}>
          <div className="form-group">
            <label htmlFor="resultDistance">📏 Дистанция (км) *</label>
            <input
              type="number"
              id="resultDistance"
              step="0.1"
              min="0.1"
              value={formData.distance}
              onChange={(e) => setFormData({ ...formData, distance: e.target.value })}
              placeholder="10.0"
              required
            />
          </div>

          <div className="form-group">
            <label htmlFor="resultTime">⏱️ Время (например: 46:37)</label>
            <input
              type="text"
              id="resultTime"
              value={formData.time}
              onChange={(e) => setFormData({ ...formData, time: e.target.value })}
              placeholder="46:37"
            />
          </div>

          <div className="form-group">
            <label htmlFor="avgHeartRate">❤️ Средний пульс</label>
            <input
              type="number"
              id="avgHeartRate"
              min="40"
              max="220"
              value={formData.heartRate}
              onChange={(e) => setFormData({ ...formData, heartRate: e.target.value })}
              placeholder="150"
            />
          </div>

          <div className="form-group">
            <label htmlFor="resultNotes">📝 Заметки</label>
            <textarea
              id="resultNotes"
              rows="3"
              value={formData.notes}
              onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
              placeholder="Дополнительные заметки..."
            />
          </div>

          <div className="form-actions">
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => setInputMethod(null)}
            >
              ← Назад
            </button>
            <button
              type="button"
              className="btn btn-secondary"
              onClick={onClose}
            >
              Отмена
            </button>
            <button
              type="submit"
              className="btn btn-primary"
              disabled={loading}
            >
              {loading ? 'Сохранение...' : '✅ Сохранить'}
            </button>
          </div>
        </form>
      ) : (
        <form onSubmit={handleSubmit}>
          <div className="form-group">
            <label htmlFor="workoutFile">📤 Выберите файл (TCX или GPX)</label>
            <input
              type="file"
              id="workoutFile"
              accept=".tcx,.gpx"
              onChange={(e) => setFile(e.target.files[0])}
              required
            />
          </div>

          <div className="form-actions">
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => setInputMethod(null)}
            >
              ← Назад
            </button>
            <button
              type="button"
              className="btn btn-secondary"
              onClick={onClose}
            >
              Отмена
            </button>
            <button
              type="submit"
              className="btn btn-primary"
              disabled={loading || !file}
            >
              {loading ? 'Загрузка...' : '📤 Загрузить'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  );
};

export default ResultModal;
