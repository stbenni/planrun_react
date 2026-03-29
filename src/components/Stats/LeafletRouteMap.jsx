import { useMemo, useRef, useEffect, useState } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const TILE_LIGHT = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
const TILE_DARK = 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png';

function isDarkTheme() {
  return document.documentElement.getAttribute('data-theme') === 'dark';
}

/**
 * Карта маршрута тренировки на базе Leaflet.
 * Показывает skeleton пока тайлы грузятся.
 * Автоматически переключает тайлы под тему.
 * hoverIndex — индекс в timeline для синхронизации с графиком пульса.
 */
const LeafletRouteMap = ({ timeline, hoverIndex }) => {
  const mapRef = useRef(null);
  const mapInstanceRef = useRef(null);
  const tileLayerRef = useRef(null);
  const hoverMarkerRef = useRef(null);
  const [tilesLoaded, setTilesLoaded] = useState(false);

  const { coords, indexToCoord } = useMemo(() => {
    if (!Array.isArray(timeline)) return { coords: [], indexToCoord: {} };
    const c = [];
    const m = {};
    timeline.forEach((p, i) => {
      if (p.latitude != null && p.longitude != null) {
        m[i] = c.length;
        c.push([p.latitude, p.longitude]);
      }
    });
    return { coords: c, indexToCoord: m };
  }, [timeline]);

  // Инициализация карты
  useEffect(() => {
    if (coords.length < 2 || !mapRef.current) return;

    if (mapInstanceRef.current) {
      mapInstanceRef.current.remove();
    }

    setTilesLoaded(false);
    const dark = isDarkTheme();

    const map = L.map(mapRef.current, {
      scrollWheelZoom: false,
      zoomControl: true,
      dragging: true,
      attributionControl: false,
    });

    const tileLayer = L.tileLayer(dark ? TILE_DARK : TILE_LIGHT, {
      maxZoom: 19,
      subdomains: dark ? 'abcd' : 'abc',
    }).addTo(map);
    tileLayerRef.current = tileLayer;

    // Отслеживаем загрузку тайлов
    tileLayer.once('load', () => setTilesLoaded(true));

    const polyline = L.polyline(coords, {
      color: '#FF4500',
      weight: 3.5,
      opacity: 0.9,
    }).addTo(map);

    const startIcon = L.divIcon({
      className: 'route-marker route-marker-start',
      html: '<div></div>',
      iconSize: [12, 12],
      iconAnchor: [6, 6],
    });
    const endIcon = L.divIcon({
      className: 'route-marker route-marker-end',
      html: '<div></div>',
      iconSize: [12, 12],
      iconAnchor: [6, 6],
    });

    L.marker(coords[0], { icon: startIcon }).addTo(map);
    L.marker(coords[coords.length - 1], { icon: endIcon }).addTo(map);

    map.fitBounds(polyline.getBounds(), { padding: [20, 20] });
    mapInstanceRef.current = map;

    // Слушаем переключение темы
    const observer = new MutationObserver(() => {
      const nowDark = isDarkTheme();
      const currentUrl = tileLayerRef.current?._url;
      const targetUrl = nowDark ? TILE_DARK : TILE_LIGHT;
      if (currentUrl !== targetUrl && tileLayerRef.current) {
        tileLayerRef.current.setUrl(targetUrl);
        tileLayerRef.current.options.subdomains = nowDark ? 'abcd' : 'abc';
      }
    });
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['data-theme'],
    });

    return () => {
      observer.disconnect();
      map.remove();
      mapInstanceRef.current = null;
      tileLayerRef.current = null;
      hoverMarkerRef.current = null;
    };
  }, [coords]);

  // Hover marker
  useEffect(() => {
    const map = mapInstanceRef.current;
    if (!map) return;

    if (hoverMarkerRef.current) {
      hoverMarkerRef.current.remove();
      hoverMarkerRef.current = null;
    }

    if (hoverIndex == null) return;

    let coordIdx = indexToCoord[hoverIndex];
    if (coordIdx == null) {
      let bestDist = Infinity;
      for (const [tlIdx, cIdx] of Object.entries(indexToCoord)) {
        const dist = Math.abs(Number(tlIdx) - hoverIndex);
        if (dist < bestDist) {
          bestDist = dist;
          coordIdx = cIdx;
        }
      }
    }

    if (coordIdx == null || !coords[coordIdx]) return;

    const hoverIcon = L.divIcon({
      className: 'route-marker route-marker-hover',
      html: '<div></div>',
      iconSize: [14, 14],
      iconAnchor: [7, 7],
    });

    hoverMarkerRef.current = L.marker(coords[coordIdx], {
      icon: hoverIcon,
      zIndexOffset: 1000,
    }).addTo(map);
  }, [hoverIndex, coords, indexToCoord]);

  if (coords.length < 2) return null;

  return (
    <div className="leaflet-route-map-wrap">
      {!tilesLoaded && (
        <div className="leaflet-route-map-skeleton">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
            <path d="M12 22s-8-4.5-8-11.8A8 8 0 0 1 12 2a8 8 0 0 1 8 8.2c0 7.3-8 11.8-8 11.8z" />
            <circle cx="12" cy="10" r="3" />
          </svg>
          <span>Загрузка карты…</span>
        </div>
      )}
      <div ref={mapRef} className="leaflet-route-map" style={{ opacity: tilesLoaded ? 1 : 0 }} />
    </div>
  );
};

export default LeafletRouteMap;
