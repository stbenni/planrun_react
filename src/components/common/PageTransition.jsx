/**
 * Компонент для плавных переходов между страницами
 * Создает ощущение единого приложения без видимых переходов
 */

import React from 'react';
import { useLocation } from 'react-router-dom';
import './PageTransition.css';

const PageTransition = ({ children }) => {
  const location = useLocation();

  return (
    <div className="page-transition" key={location.pathname}>
      {children}
    </div>
  );
};

export default PageTransition;
