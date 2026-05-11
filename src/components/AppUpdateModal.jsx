import { useState } from 'react';
import { AlertTriangle, Download, ExternalLink } from 'lucide-react';
import './AppUpdateModal.css';

export default function AppUpdateModal({ updateInfo, onDismiss }) {
  const [phase, setPhase] = useState('idle');

  if (!updateInfo) return null;

  const downloadUrl = updateInfo.download_url;
  const canDismiss = !updateInfo.force_update;

  async function openDownload() {
    if (!downloadUrl) {
      setPhase('error');
      return;
    }

    setPhase('opening');

    try {
      try {
        const { Browser } = await import('@capacitor/browser');
        await Browser.open({ url: downloadUrl });
        setPhase('opened');
        return;
      } catch {
        const openedWindow = window.open(downloadUrl, '_system');
        if (!openedWindow) {
          window.location.href = downloadUrl;
        }
      }

      setPhase('opened');
    } catch {
      setPhase('error');
    }
  }

  function retry() {
    setPhase('idle');
  }

  return (
    <div className="app-update-overlay" role="dialog" aria-modal="true" aria-labelledby="app-update-title">
      <div className="app-update-modal">
        <div className={`app-update-icon ${phase === 'error' ? 'app-update-icon--error' : ''}`}>
          {phase === 'error' ? (
            <AlertTriangle size={26} strokeWidth={1.8} />
          ) : phase === 'opened' ? (
            <ExternalLink size={26} strokeWidth={1.8} />
          ) : (
            <Download size={26} strokeWidth={1.8} />
          )}
        </div>

        {phase === 'idle' && (
          <>
            <h2 id="app-update-title">Новая версия</h2>
            <p>
              Доступна версия <span className="app-update-version">{updateInfo.version}</span>.
              Установите обновление, чтобы получить свежие исправления и улучшения.
            </p>
            <div className="app-update-actions">
              <button className="btn btn-primary" type="button" onClick={openDownload}>
                Обновить
              </button>
              {canDismiss && (
                <button className="app-update-btn-secondary" type="button" onClick={onDismiss}>
                  Позже
                </button>
              )}
            </div>
          </>
        )}

        {phase === 'opening' && (
          <>
            <h2 id="app-update-title">Открываем загрузку</h2>
            <p>Сейчас откроется страница скачивания APK. После загрузки подтвердите установку Android.</p>
            <div className="app-update-spinner" aria-hidden="true" />
          </>
        )}

        {phase === 'opened' && (
          <>
            <h2 id="app-update-title">Загрузка открыта</h2>
            <p>Если скачивание не началось автоматически, откройте файл обновления ещё раз.</p>
            <div className="app-update-actions">
              <button className="btn btn-primary" type="button" onClick={openDownload}>
                Открыть снова
              </button>
              {canDismiss && (
                <button className="app-update-btn-secondary" type="button" onClick={onDismiss}>
                  Закрыть
                </button>
              )}
            </div>
          </>
        )}

        {phase === 'error' && (
          <>
            <h2 id="app-update-title">Не удалось открыть загрузку</h2>
            <p>Проверьте подключение и попробуйте ещё раз.</p>
            <div className="app-update-actions">
              <button className="btn btn-primary" type="button" onClick={retry}>
                Попробовать снова
              </button>
              {canDismiss && (
                <button className="app-update-btn-secondary" type="button" onClick={onDismiss}>
                  Позже
                </button>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  );
}
