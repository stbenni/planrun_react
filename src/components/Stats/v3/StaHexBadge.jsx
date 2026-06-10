const HEX_CLIP = 'polygon(50% 0%, 93% 25%, 93% 75%, 50% 100%, 7% 75%, 7% 25%)';

export default function StaHexBadge({ b, size = 58 }) {
  const pct = Math.round((b.pct || 0) * 100);
  return (
    <div className="statv3-hex-wrap" style={{ width: size + 12 }}>
      <div className={`statv3-hex statv3-hex--${b.tier} ${b.got ? 'is-got' : ''}`} style={{ width: size, height: size }}>
        <span className="statv3-hex__ring" style={{ clipPath: HEX_CLIP }} />
        <span className="statv3-hex__inner" style={{ clipPath: HEX_CLIP, fontSize: size * 0.4 }}>{b.ic}</span>
        {b.fresh && <span className="statv3-hex__star">★</span>}
        {!b.got && pct > 0 && <span className="statv3-hex__pct">{pct}%</span>}
      </div>
      <div className={`statv3-hex__title ${b.got ? 'is-got' : ''}`}>{b.title}</div>
    </div>
  );
}
