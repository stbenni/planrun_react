/**
 * Частицы — Canvas, как в showcase ParticlesBackground
 * Плавающие точки без линий, requestAnimationFrame, wrap по краям
 */

import { useRef, useEffect } from 'react';

const rand = (min, max) => min + Math.random() * (max - min);

const ParticlesBackground = ({ className = '', isDark = true }) => {
  const canvasRef = useRef(null);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    let animationId;
    let particles = [];

    const resize = () => {
      const dpr = window.devicePixelRatio || 1;
      const rect = canvas.getBoundingClientRect();
      if (rect.width <= 0 || rect.height <= 0) return;
      canvas.width = rect.width * dpr;
      canvas.height = rect.height * dpr;
      canvas.style.width = `${rect.width}px`;
      canvas.style.height = `${rect.height}px`;
      ctx.scale(dpr, dpr);

      const count = Math.floor((rect.width * rect.height) / 10000);
      particles = Array.from({ length: count }, () => ({
        x: rand(0, rect.width),
        y: rand(0, rect.height),
        vx: rand(-0.15, 0.15),
        vy: rand(-0.15, 0.15),
        size: rand(0.5, 2.5),
        opacity: rand(0.08, 0.43),
      }));
    };

    const draw = () => {
      const rect = canvas.getBoundingClientRect();
      if (rect.width <= 0 || rect.height <= 0 || particles.length === 0) {
        animationId = requestAnimationFrame(draw);
        return;
      }
      ctx.clearRect(0, 0, rect.width, rect.height);

      particles.forEach((p) => {
        p.x += p.vx;
        p.y += p.vy;

        if (p.x < 0) p.x = rect.width;
        if (p.x > rect.width) p.x = 0;
        if (p.y < 0) p.y = rect.height;
        if (p.y > rect.height) p.y = 0;

        const alpha = Math.min(1, isDark ? p.opacity : p.opacity * 2);
        ctx.beginPath();
        ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
        ctx.fillStyle = isDark
          ? `hsla(14, 90%, 55%, ${alpha})`
          : `hsla(14, 80%, 60%, ${alpha})`;
        ctx.fill();
      });

      animationId = requestAnimationFrame(draw);
    };

    resize();
    draw();

    const ro = new ResizeObserver(resize);
    ro.observe(canvas);

    return () => {
      cancelAnimationFrame(animationId);
      ro.disconnect();
    };
  }, [isDark]);

  return (
    <canvas
      ref={canvasRef}
      className={className}
      aria-hidden
      style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', pointerEvents: 'none' }}
    />
  );
};

export default ParticlesBackground;
