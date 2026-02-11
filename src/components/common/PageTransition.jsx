/**
 * Компонент для плавных переходов между страницами.
 * Обёртка без key — не перемонтируется при навигации (хедер и контент-зона остаются).
 * Анимация применяется к внутреннему контенту через .page-transition-content.
 */

import React from 'react';
import './PageTransition.css';

const PageTransition = ({ children }) => {
  return <div className="page-transition">{children}</div>;
};

export default PageTransition;
