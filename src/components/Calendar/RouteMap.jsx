/**
 * RouteMap - Компонент для отображения GPS маршрутов тренировок
 * В стиле Strava (карта с треком)
 */

import React, { useState, useEffect, useRef } from 'react';
import { MapPinIcon, MountainIcon, PaceIcon } from '../common/Icons';
import './RouteMap.css';

const RouteMap = ({ workout, gpxData, coordinates }) => {
  const [mapLoaded, setMapLoaded] = useState(false);
  const mapContainerRef = useRef(null);

  useEffect(() => {
    // Если есть координаты или GPX данные, загружаем карту
    if (coordinates || gpxData) {
      loadMap();
    }
  }, [coordinates, gpxData]);

  const loadMap = () => {
    // Используем OpenStreetMap через Leaflet или простой статический API
    // Для простоты используем статический API карт
    if (!mapContainerRef.current) return;

    // Если есть координаты, показываем карту
    if (coordinates && coordinates.length > 0) {
      // Вычисляем границы маршрута
      const lats = coordinates.map(c => c.lat);
      const lngs = coordinates.map(c => c.lng);
      const minLat = Math.min(...lats);
      const maxLat = Math.max(...lats);
      const minLng = Math.min(...lngs);
      const maxLng = Math.max(...lngs);
      
      // Центр карты
      const centerLat = (minLat + maxLat) / 2;
      const centerLng = (minLng + maxLng) / 2;
      
      // Используем статический API OpenStreetMap
      const zoom = 13;
      const width = mapContainerRef.current.offsetWidth || 600;
      const height = 300;
      
      // OpenStreetMap без API ключа (для Mapbox задать VITE_MAPBOX_TOKEN в .env)
      // Для production нужно использовать свой API ключ
      const osmUrl = `https://www.openstreetmap.org/export/embed.html?bbox=${minLng-0.01},${minLat-0.01},${maxLng+0.01},${maxLat+0.01}&layer=mapnik&marker=${centerLat},${centerLng}`;
      
      // Простое решение: показываем iframe с OpenStreetMap
      const iframe = document.createElement('iframe');
      iframe.src = osmUrl;
      iframe.width = '100%';
      iframe.height = '300';
      iframe.frameBorder = '0';
      iframe.style.border = 'none';
      iframe.style.borderRadius = '12px';
      
      mapContainerRef.current.innerHTML = '';
      mapContainerRef.current.appendChild(iframe);
      
      setMapLoaded(true);
    } else if (gpxData) {
      // Если есть GPX данные, парсим их (упрощенная версия)
      mapContainerRef.current.innerHTML = '<div class="map-placeholder">Карта маршрута будет отображена здесь</div>';
      setMapLoaded(true);
    }
  };

  if (!coordinates && !gpxData) {
    return null;
  }

  return (
    <div className="route-map-container">
      <div className="route-map-header">
        <h3 className="route-map-title"><MapPinIcon size={20} className="title-icon" aria-hidden /> Маршрут тренировки</h3>
        {workout?.distance_km && (
          <span className="route-distance">{workout.distance_km} км</span>
        )}
      </div>
      <div 
        className="route-map"
        ref={mapContainerRef}
      >
        {!mapLoaded && (
          <div className="map-loading">
            <div className="spinner"></div>
            <span>Загрузка карты...</span>
          </div>
        )}
      </div>
      {workout?.elevation_gain && (
        <div className="route-stats">
          <div className="route-stat">
            <span className="stat-icon" aria-hidden><MountainIcon size={18} /></span>
            <span className="stat-value">{Math.round(workout.elevation_gain)}</span>
            <span className="stat-unit">м</span>
          </div>
          {workout?.avg_pace && (
            <div className="route-stat">
              <span className="stat-icon" aria-hidden><PaceIcon size={18} /></span>
              <span className="stat-value">{workout.avg_pace}</span>
              <span className="stat-unit">/км</span>
            </div>
          )}
        </div>
      )}
    </div>
  );
};

export default RouteMap;
