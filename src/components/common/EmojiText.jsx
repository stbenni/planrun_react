/**
 * EmojiText — рендерит текст, заменяя unicode-эмодзи на локальные Apple-картинки
 * (один вид на всех ОС). Карта native→unified грузится лениво из @emoji-mart/data;
 * до загрузки и для текста без эмодзи возвращается обычная строка (нулевой оверхед).
 * Границы эмодзи определяются через Intl.Segmenter (ZWJ/флаги/keycap = один кластер).
 */

import { useState, useEffect } from 'react';
import { appleEmojiImageURL } from './emojiAssets';
import './EmojiText.css';

const HAS_EMOJI = /\p{Extended_Pictographic}/u;

let nativeToUnified = null;
let mapPromise = null;
let segmenter;

function ensureMap() {
  if (nativeToUnified) return Promise.resolve(nativeToUnified);
  if (!mapPromise) {
    mapPromise = import('@emoji-mart/data')
      .then((mod) => {
        const data = mod.default || mod;
        const m = new Map();
        for (const id in data.emojis) {
          const e = data.emojis[id];
          if (!e || !e.skins) continue;
          for (const s of e.skins) {
            if (s.native && s.unified) m.set(s.native, s.unified);
          }
        }
        nativeToUnified = m;
        return m;
      })
      .catch(() => {
        nativeToUnified = new Map();
        return nativeToUnified;
      });
  }
  return mapPromise;
}

function getSegmenter() {
  if (segmenter === undefined) {
    segmenter = (typeof Intl !== 'undefined' && Intl.Segmenter)
      ? new Intl.Segmenter('ru', { granularity: 'grapheme' })
      : null;
  }
  return segmenter;
}

export default function EmojiText({ text }) {
  const content = text || '';
  const hasEmoji = content && HAS_EMOJI.test(content);
  const [ready, setReady] = useState(!!nativeToUnified);

  useEffect(() => {
    if (!hasEmoji || nativeToUnified) return undefined;
    let alive = true;
    ensureMap().then(() => { if (alive) setReady(true); });
    return () => { alive = false; };
  }, [hasEmoji]);

  if (!hasEmoji) return content;

  const seg = getSegmenter();
  const map = nativeToUnified;
  if (!ready || !map || !seg) return content;

  const nodes = [];
  let buf = '';
  let key = 0;
  const flush = () => { if (buf) { nodes.push(buf); buf = ''; } };

  for (const { segment } of seg.segment(content)) {
    const unified = map.get(segment);
    if (unified) {
      flush();
      nodes.push(
        <img
          key={`e${key++}`}
          className="emoji-img"
          src={appleEmojiImageURL(unified)}
          alt={segment}
          draggable={false}
          loading="lazy"
        />,
      );
    } else {
      buf += segment;
    }
  }
  flush();
  return nodes;
}
