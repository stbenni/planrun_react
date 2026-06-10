import { useMemo, useRef, useEffect, useState } from 'react';
import mapboxgl from 'mapbox-gl';
import 'mapbox-gl/dist/mapbox-gl.css';

const TOKEN = import.meta.env.VITE_MAPBOX_TOKEN;
const STYLE_LIGHT = 'mapbox://styles/mapbox/streets-v12';
const STYLE_DARK = 'mapbox://styles/mapbox/dark-v11';
const ROUTE_SRC = 'route-src';
const ROUTE_LAYER = 'route-line';

function isDarkTheme() {
  return document.documentElement.getAttribute('data-theme') === 'dark';
}

function styleForTheme() {
  return isDarkTheme() ? STYLE_DARK : STYLE_LIGHT;
}

function markerEl(cls) {
  const el = document.createElement('div');
  el.className = cls;
  el.appendChild(document.createElement('div'));
  return el;
}

/**
 * Карта маршрута тренировки на Mapbox GL JS (векторная, 2D / mercator).
 * Тот же API и CSS-классы, что у LeafletRouteMap — drop-in замена при наличии токена.
 * Переключает стиль streets-v12 / dark-v11 под тему.
 * hoverIndex — индекс в timeline для синхронизации с графиком пульса.
 */
const MapboxRouteMap = ({ timeline, hoverIndex }) => {
  const mapRef = useRef(null);
  const mapInstanceRef = useRef(null);
  const hoverMarkerRef = useRef(null);
  const styleUrlRef = useRef(styleForTheme());
  const [loaded, setLoaded] = useState(false);

  const { coords, indexToCoord } = useMemo(() => {
    if (!Array.isArray(timeline)) return { coords: [], indexToCoord: {} };
    const c = [];
    const m = {};
    timeline.forEach((p, i) => {
      if (p.latitude != null && p.longitude != null) {
        m[i] = c.length;
        c.push([p.longitude, p.latitude]);
      }
    });
    return { coords: c, indexToCoord: m };
  }, [timeline]);

  useEffect(() => {
    if (coords.length < 2 || !mapRef.current || !TOKEN) return undefined;
    mapboxgl.accessToken = TOKEN;
    setLoaded(false);

    const bounds = coords.reduce(
      (b, c) => b.extend(c),
      new mapboxgl.LngLatBounds(coords[0], coords[0]),
    );

    styleUrlRef.current = styleForTheme();
    const map = new mapboxgl.Map({
      container: mapRef.current,
      style: styleUrlRef.current,
      bounds,
      fitBoundsOptions: { padding: 24 },
      projection: 'mercator',
      attributionControl: false,
      scrollZoom: false,
      dragRotate: false,
      pitchWithRotate: false,
      touchPitch: false,
    });
    map.touchZoomRotate.disableRotation();
    map.addControl(new mapboxgl.NavigationControl({ showCompass: false }), 'top-left');
    mapInstanceRef.current = map;

    const addRoute = () => {
      if (map.getSource(ROUTE_SRC)) return;
      map.addSource(ROUTE_SRC, {
        type: 'geojson',
        data: { type: 'Feature', geometry: { type: 'LineString', coordinates: coords } },
      });
      map.addLayer({
        id: ROUTE_LAYER,
        type: 'line',
        source: ROUTE_SRC,
        layout: { 'line-cap': 'round', 'line-join': 'round' },
        paint: { 'line-color': '#FF4500', 'line-width': 3.5, 'line-opacity': 0.95 },
      });
    };

    const fitRoute = () => {
      if (!mapInstanceRef.current) return;
      mapInstanceRef.current.resize();
      mapInstanceRef.current.fitBounds(bounds, { padding: 28, duration: 0 });
    };

    map.on('style.load', addRoute);
    map.on('load', () => {
      addRoute();
      fitRoute();
      setLoaded(true);
    });

    const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(fitRoute) : null;
    if (ro && mapRef.current) ro.observe(mapRef.current);

    new mapboxgl.Marker({ element: markerEl('route-marker route-marker-start'), anchor: 'center' })
      .setLngLat(coords[0]).addTo(map);
    new mapboxgl.Marker({ element: markerEl('route-marker route-marker-end'), anchor: 'center' })
      .setLngLat(coords[coords.length - 1]).addTo(map);

    const observer = new MutationObserver(() => {
      const target = styleForTheme();
      if (target !== styleUrlRef.current) {
        styleUrlRef.current = target;
        map.setStyle(target);
      }
    });
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    return () => {
      observer.disconnect();
      ro?.disconnect();
      hoverMarkerRef.current?.remove();
      hoverMarkerRef.current = null;
      map.remove();
      mapInstanceRef.current = null;
    };
  }, [coords]);

  useEffect(() => {
    const map = mapInstanceRef.current;
    if (!map) return;

    if (hoverIndex == null) {
      hoverMarkerRef.current?.remove();
      hoverMarkerRef.current = null;
      return;
    }

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

    if (!hoverMarkerRef.current) {
      hoverMarkerRef.current = new mapboxgl.Marker({
        element: markerEl('route-marker route-marker-hover'),
        anchor: 'center',
      }).setLngLat(coords[coordIdx]).addTo(map);
    } else {
      hoverMarkerRef.current.setLngLat(coords[coordIdx]);
    }
  }, [hoverIndex, coords, indexToCoord]);

  if (coords.length < 2) return null;

  return (
    <div className="leaflet-route-map-wrap">
      {!loaded && (
        <div className="leaflet-route-map-skeleton">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
            <path d="M12 22s-8-4.5-8-11.8A8 8 0 0 1 12 2a8 8 0 0 1 8 8.2c0 7.3-8 11.8-8 11.8z" />
            <circle cx="12" cy="10" r="3" />
          </svg>
          <span>Загрузка карты…</span>
        </div>
      )}
      <div ref={mapRef} className="leaflet-route-map" style={{ opacity: loaded ? 1 : 0 }} />
    </div>
  );
};

export default MapboxRouteMap;
