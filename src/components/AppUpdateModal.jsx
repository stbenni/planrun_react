import { useEffect, useRef, useState } from 'react';
import { AlertTriangle, CheckCircle2, Download } from 'lucide-react';
import './AppUpdateModal.css';

const APK_FILE_NAME = 'planrun-update.apk';

function formatMb(bytes) {
  if (!Number.isFinite(bytes) || bytes <= 0) return null;
  return (bytes / 1024 / 1024).toFixed(1);
}

export default function AppUpdateModal({ updateInfo, onDismiss }) {
  const [phase, setPhase] = useState('idle');
  const [progress, setProgress] = useState(0);
  const [downloadedBytes, setDownloadedBytes] = useState(0);
  const [totalBytes, setTotalBytes] = useState(0);
  const [errorMessage, setErrorMessage] = useState('');
  const listenerRef = useRef(null);

  useEffect(() => {
    return () => {
      const handle = listenerRef.current;
      listenerRef.current = null;
      if (handle?.remove) handle.remove();
    };
  }, []);

  if (!updateInfo) return null;

  const downloadUrl = updateInfo.download_url;
  const canDismiss = !updateInfo.force_update && phase !== 'downloading' && phase !== 'installing';
  const percent = Math.min(100, Math.max(0, Math.round(progress)));

  async function startUpdate() {
    if (!downloadUrl) {
      setErrorMessage('Не указан адрес для загрузки APK.');
      setPhase('error');
      return;
    }

    setPhase('downloading');
    setProgress(0);
    setDownloadedBytes(0);
    setTotalBytes(0);
    setErrorMessage('');

    try {
      const { Filesystem, Directory } = await import('@capacitor/filesystem');

      try {
        await Filesystem.deleteFile({ path: APK_FILE_NAME, directory: Directory.Cache });
      } catch {
        // Старого файла может не быть — это норма.
      }

      listenerRef.current = await Filesystem.addListener('progress', (status) => {
        const current = Number(status?.bytes) || 0;
        const total = Number(status?.contentLength) || 0;
        setDownloadedBytes(current);
        if (total > 0) {
          setTotalBytes(total);
          setProgress((current / total) * 100);
        }
      });

      const result = await Filesystem.downloadFile({
        url: downloadUrl,
        path: APK_FILE_NAME,
        directory: Directory.Cache,
        recursive: true,
        progress: true,
      });

      listenerRef.current?.remove?.();
      listenerRef.current = null;

      const apkPath = result?.path;
      if (!apkPath) {
        throw new Error('Загрузка завершилась без пути к файлу.');
      }

      setProgress(100);
      setPhase('installing');

      const { FileOpener } = await import('@capacitor-community/file-opener');
      await FileOpener.open({
        filePath: apkPath,
        contentType: 'application/vnd.android.package-archive',
      });
    } catch (err) {
      listenerRef.current?.remove?.();
      listenerRef.current = null;
      setErrorMessage(String(err?.message || err) || 'Не удалось обновить приложение.');
      setPhase('error');
    }
  }

  function retry() {
    setErrorMessage('');
    setPhase('idle');
  }

  return (
    <div className="app-update-overlay" role="dialog" aria-modal="true" aria-labelledby="app-update-title">
      <div className="app-update-modal">
        <div className={`app-update-icon ${phase === 'error' ? 'app-update-icon--error' : ''}`}>
          {phase === 'error' ? (
            <AlertTriangle size={26} strokeWidth={1.8} />
          ) : phase === 'installing' ? (
            <CheckCircle2 size={26} strokeWidth={1.8} />
          ) : (
            <Download size={26} strokeWidth={1.8} />
          )}
        </div>

        {phase === 'idle' && (
          <>
            <h2 id="app-update-title">Новая версия</h2>
            <p>
              Доступна версия <span className="app-update-version">{updateInfo.version}</span>.
              Установите обновление, чтобы получить свежие исправления.
            </p>
            <div className="app-update-actions">
              <button className="btn btn-primary" type="button" onClick={startUpdate}>
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

        {phase === 'downloading' && (
          <>
            <h2 id="app-update-title">Загрузка обновления</h2>
            <p>
              Версия <span className="app-update-version">{updateInfo.version}</span>
              {' · '}
              {formatMb(downloadedBytes) || '0.0'}
              {totalBytes > 0 ? ` / ${formatMb(totalBytes)} МБ` : ' МБ'}
            </p>
            <div className="app-update-progress-track" aria-hidden="true">
              <div
                className="app-update-progress-fill"
                style={{ width: `${percent}%` }}
              />
            </div>
            <p className="app-update-progress-percent">{percent}%</p>
          </>
        )}

        {phase === 'installing' && (
          <>
            <h2 id="app-update-title">Готово к установке</h2>
            <p>Подтвердите установку в окне Android — приложение перезапустится на новой версии.</p>
            <div className="app-update-spinner" aria-hidden="true" />
          </>
        )}

        {phase === 'error' && (
          <>
            <h2 id="app-update-title">Не удалось обновить</h2>
            <p>{errorMessage || 'Проверьте подключение и попробуйте ещё раз.'}</p>
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
