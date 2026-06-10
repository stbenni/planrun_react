/**
 * MapLoadingSkeleton — заглушка-анимация на время загрузки карты.
 * Используется как Suspense fallback для lazy LeafletRouteMap (пока качается чанк Leaflet),
 * чтобы место под карту не было пустым. Заполняет контейнер по высоте.
 */
import './MapLoadingSkeleton.css';

export default function MapLoadingSkeleton() {
  return (
    <div className="map-loading-skeleton" aria-busy="true" aria-live="polite">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
        <path d="M12 22s-8-4.5-8-11.8A8 8 0 0 1 12 2a8 8 0 0 1 8 8.2c0 7.3-8 11.8-8 11.8z" />
        <circle cx="12" cy="10" r="3" />
      </svg>
      <span>Загрузка карты…</span>
    </div>
  );
}
