import { useState } from 'react';

/**
 * ThemeToggle - Компонент переключения темы
 */
const ThemeToggle = () => {
  const [currentTheme, setCurrentTheme] = useState(
    () => document.documentElement.getAttribute('data-theme') || 'light'
  );

  const toggleTheme = () => {
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', newTheme);
    document.body.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    setCurrentTheme(newTheme);
  };

  return (
    <button
      type="button"
      onClick={toggleTheme}
      className="theme-toggle"
      aria-label={currentTheme === 'light' ? 'Переключить на тёмную тему' : 'Переключить на светлую тему'}
      title={currentTheme === 'light' ? 'Тёмная тема' : 'Светлая тема'}
    >
      {currentTheme === 'light' ? '🌙' : '☀️'}
    </button>
  );
};

export default ThemeToggle;
