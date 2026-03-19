import React from 'react';
import { logger } from '../../utils/logger';
import { isChunkLoadError } from '../../utils/lazyWithRetry';

const shellStyle = {
  minHeight: '100vh',
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
  padding: '24px',
  boxSizing: 'border-box',
};

const cardStyle = {
  width: '100%',
  maxWidth: '520px',
  padding: '24px',
  borderRadius: '20px',
  background: 'var(--surface-primary, #ffffff)',
  boxShadow: '0 20px 60px rgba(15, 23, 42, 0.08)',
  border: '1px solid rgba(15, 23, 42, 0.08)',
};

const titleStyle = {
  margin: '0 0 10px',
  fontSize: '24px',
  lineHeight: 1.2,
};

const textStyle = {
  margin: '0 0 18px',
  color: 'var(--text-secondary, #475569)',
  lineHeight: 1.5,
};

const actionsStyle = {
  display: 'flex',
  gap: '12px',
  flexWrap: 'wrap',
};

class AppErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = { error: null };
  }

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error, errorInfo) {
    logger.error('App error boundary', error?.stack || error, errorInfo?.componentStack || '');
  }

  componentDidUpdate(prevProps) {
    if (this.state.error && prevProps.resetKey !== this.props.resetKey) {
      this.setState({ error: null });
    }
  }

  handleRetry = () => {
    this.setState({ error: null });
  };

  handleReload = () => {
    if (typeof window !== 'undefined') {
      window.location.reload();
    }
  };

  handleGoHome = () => {
    if (typeof window !== 'undefined') {
      window.location.assign('/');
    }
  };

  render() {
    const { error } = this.state;

    if (!error) {
      return this.props.children;
    }

    const chunkError = isChunkLoadError(error);
    const title = chunkError ? 'Страница обновилась на сервере' : 'Не удалось отрисовать экран';
    const description = chunkError
      ? 'Похоже, браузер держит старую версию чанка. Обновите страницу, чтобы загрузить актуальную сборку.'
      : 'Произошла ошибка интерфейса. Можно попробовать перерисовать экран или перезагрузить приложение.';

    return (
      <div style={shellStyle}>
        <div style={cardStyle}>
          <h1 style={titleStyle}>{title}</h1>
          <p style={textStyle}>{description}</p>
          <div style={actionsStyle}>
            {!chunkError && (
              <button type="button" className="btn btn-secondary" onClick={this.handleRetry}>
                Повторить
              </button>
            )}
            <button type="button" className="btn btn-primary" onClick={this.handleReload}>
              Обновить страницу
            </button>
            <button type="button" className="btn btn-ghost" onClick={this.handleGoHome}>
              На главную
            </button>
          </div>
        </div>
      </div>
    );
  }
}

export default AppErrorBoundary;
