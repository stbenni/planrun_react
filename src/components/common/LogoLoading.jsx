/**
 * Индикатор загрузки: логотип planRUN с shimmer-анимацией (как в скелетонах)
 */
import './LogoLoading.css';

const LogoLoading = ({ className = '', size = 'default' }) => (
  <div className={`logo-loading logo-loading--${size} ${className}`.trim()}>
    <span className="logo-loading-text">
      <span className="logo-plan">plan</span>
      <span className="logo-run">RUN</span>
    </span>
  </div>
);

export default LogoLoading;
