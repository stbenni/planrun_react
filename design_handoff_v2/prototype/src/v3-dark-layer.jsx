/* Unified Dark Theme — one source of truth.
   Wraps any tree to give it consistent dark liquid-glass look,
   matching the native-dark dashboards visually.                    */

function DarkLayer({ children, active = true }) {
  if (!active) return children;
  return (
    <div data-prdark="1" style={{
      width: '100%', height: '100%', position: 'relative',
      background: 'radial-gradient(120% 80% at 0% 0%, rgba(252,76,2,0.10) 0%, transparent 50%), radial-gradient(100% 70% at 100% 100%, rgba(252,76,2,0.06) 0%, transparent 55%), linear-gradient(180deg, #0F151D 0%, #0B1015 100%)',
      color: '#F1F5F9',
    }}>
      <style dangerouslySetInnerHTML={{ __html: DARK_OVERRIDES_CSS }} />
      {children}
    </div>
  );
}

const DARK_OVERRIDES_CSS = `
[data-prdark="1"] {
  color-scheme: dark;
  color: #F1F5F9;
}

/* ── Shell whites: full-height shells go transparent (DarkLayer paints) ── */
[data-prdark="1"] [style*="background: white"][style*="height: 100%"],
[data-prdark="1"] [style*="background: rgb(255, 255, 255)"][style*="height: 100%"],
[data-prdark="1"] [style*="background:#fff"][style*="height: 100%"],
[data-prdark="1"] [style*="background: white"][style*="height:100%"] {
  background-color: transparent !important;
  background-image: none !important;
}

/* ── Glass cards: white surfaces (not shells) → translucent dark glass + blur ── */
[data-prdark="1"] [style*="background: white"]:not([style*="height: 100%"]):not([style*="height:100%"]),
[data-prdark="1"] [style*="background: rgb(255, 255, 255)"]:not([style*="height: 100%"]):not([style*="height:100%"]),
[data-prdark="1"] [style*="background:#fff"]:not([style*="height: 100%"]):not([style*="height:100%"]) {
  background-color: rgba(28, 34, 43, 0.62) !important;
  backdrop-filter: blur(18px) saturate(1.16) !important;
  -webkit-backdrop-filter: blur(18px) saturate(1.16) !important;
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,0.05),
    0 12px 28px rgba(0,0,0,0.35),
    0 4px 12px rgba(252,76,2,0.06) !important;
}

/* ── Shell gradients (warm orange radials over dark) ──────────── */
[data-prdark="1"] [style*="#FAF7F3"],
[data-prdark="1"] [style*="rgb(244, 247, 251)"],
[data-prdark="1"] [style*="#F4F7FB"] {
  background-image:
    radial-gradient(120% 80% at 0% 0%, rgba(252,76,2,0.10) 0%, transparent 50%),
    radial-gradient(100% 70% at 100% 100%, rgba(252,76,2,0.06) 0%, transparent 55%),
    linear-gradient(180deg, #0F151D 0%, #0B1015 100%) !important;
  background-color: transparent !important;
}

/* ── Cards: any white / off-white surface → tinted glass ─────── */
[data-prdark="1"] [style*="rgba(255,255,255,0.72)"],
[data-prdark="1"] [style*="rgba(255, 255, 255, 0.72)"],
[data-prdark="1"] [style*="rgba(255, 255, 255, 0.78)"],
[data-prdark="1"] [style*="rgba(255,255,255,0.78)"],
[data-prdark="1"] [style*="rgba(255,255,255,0.92)"],
[data-prdark="1"] [style*="rgba(255, 255, 255, 0.92)"] {
  background-color: rgba(28, 34, 43, 0.62) !important;
  background-image: none !important;
  backdrop-filter: blur(18px) saturate(1.16) !important;
  -webkit-backdrop-filter: blur(18px) saturate(1.16) !important;
}

/* surf2 = #F8FAFC ─ slightly elevated dark surface */
[data-prdark="1"] [style*="rgb(248, 250, 252)"],
[data-prdark="1"] [style*="#F8FAFC"] {
  background-color: #13181F !important;
}
/* surf3 = #F1F5F9 ─ darker tier */
[data-prdark="1"] [style*="rgb(241, 245, 249)"],
[data-prdark="1"] [style*="#F1F5F9"] {
  background-color: #1C222B !important;
}

/* ── Tinted wash backgrounds — keep colored, deepen ──────────── */
[data-prdark="1"] [style*="#FFF4F0"],
[data-prdark="1"] [style*="rgb(255, 244, 240)"] {
  background-color: rgba(252,76,2,0.12) !important;
}
[data-prdark="1"] [style*="#FFE5D9"] {
  background-color: rgba(252,76,2,0.18) !important;
}
[data-prdark="1"] [style*="#DCFCE7"],
[data-prdark="1"] [style*="rgb(220, 252, 231)"] {
  background-color: rgba(34,197,94,0.15) !important;
  color: #6EE7B7 !important;
}
[data-prdark="1"] [style*="#FEE2E2"],
[data-prdark="1"] [style*="rgb(254, 226, 226)"] {
  background-color: rgba(239,68,68,0.15) !important;
  color: #FCA5A5 !important;
}
[data-prdark="1"] [style*="#FEF9C3"],
[data-prdark="1"] [style*="rgb(254, 249, 195)"] {
  background-color: rgba(234,179,8,0.15) !important;
  color: #FDE047 !important;
}
[data-prdark="1"] [style*="#DBEAFE"],
[data-prdark="1"] [style*="rgb(219, 234, 254)"] {
  background-color: rgba(59,130,246,0.15) !important;
  color: #93C5FD !important;
}

/* ── Text: ink → light ────────────────────────────────────────── */
[data-prdark="1"] [style*="color: rgb(15, 23, 42)"],
[data-prdark="1"] [style*="color:#0F172A"] {
  color: #F1F5F9 !important;
}
[data-prdark="1"] [style*="color: rgb(71, 85, 105)"],
[data-prdark="1"] [style*="color:#475569"] {
  color: #CBD5E1 !important;
}
[data-prdark="1"] [style*="color: rgb(100, 116, 139)"],
[data-prdark="1"] [style*="color:#64748B"] {
  color: #94A3B8 !important;
}
[data-prdark="1"] [style*="color: rgb(148, 163, 184)"],
[data-prdark="1"] [style*="color:#94A3B8"] {
  color: #64748B !important;
}

/* ── Borders: light gray → dark line w/ subtle orange tint ───── */
[data-prdark="1"] [style*="border: 1px solid rgb(226, 232, 240)"],
[data-prdark="1"] [style*="border:1px solid #E2E8F0"],
[data-prdark="1"] [style*="border: 1.5px solid rgb(226, 232, 240)"],
[data-prdark="1"] [style*="border-bottom: 1px solid rgb(226, 232, 240)"],
[data-prdark="1"] [style*="border-top: 1px solid rgb(226, 232, 240)"],
[data-prdark="1"] [style*="border-left: 1px solid rgb(226, 232, 240)"],
[data-prdark="1"] [style*="border-right: 1px solid rgb(226, 232, 240)"],
[data-prdark="1"] [style*="border-color: rgb(226, 232, 240)"] {
  border-color: rgba(252,76,2,0.14) !important;
}
[data-prdark="1"] [style*="rgb(203, 213, 225)"],
[data-prdark="1"] [style*="#CBD5E1"] {
  border-color: #2A323D !important;
}
/* Dashed light borders */
[data-prdark="1"] [style*="dashed"][style*="rgb(203, 213, 225)"],
[data-prdark="1"] [style*="dashed"][style*="rgb(226, 232, 240)"] {
  border-color: #2A323D !important;
}

/* ── Bottom-nav, sticky tabs, top bar (blurred surfaces) ─────── */
[data-prdark="1"] [style*="backdrop-filter: blur"] {
  background-color: rgba(28, 34, 43, 0.78) !important;
  background-image: none !important;
}
[data-prdark="1"] [style*="rgba(244,247,251,0.92)"],
[data-prdark="1"] [style*="rgba(244, 247, 251, 0.92)"] {
  background-color: rgba(15, 21, 29, 0.88) !important;
}

/* ── Status bar text ─────────────────────────────────────────── */
[data-prdark="1"] [style*="height: 36px"][style*="font-weight: 700"] {
  color: #F1F5F9 !important;
}

/* ── Inputs / textareas / selects ────────────────────────────── */
[data-prdark="1"] input,
[data-prdark="1"] textarea,
[data-prdark="1"] select {
  background-color: #1C222B !important;
  color: #F1F5F9 !important;
  border-color: #2A323D !important;
}
[data-prdark="1"] input::placeholder,
[data-prdark="1"] textarea::placeholder { color: #64748B !important; }

/* Checkboxes */
[data-prdark="1"] input[type="checkbox"] {
  accent-color: #FC4C02;
}

/* ── Specific dark bg (ink #0F172A) stays dark on both themes ─ */
[data-prdark="1"] [style*="rgb(15, 23, 42)"][style*="color: white"],
[data-prdark="1"] [style*="background: #0F172A"] {
  background-color: #050810 !important;
}

/* ── SVG strokes/icons that use currentColor inherit OK ─────── */

/* ── Liquid-glass cards: add subtle inner highlight ──────────── */
[data-prdark="1"] [style*="backdrop-filter"][style*="blur(20px)"],
[data-prdark="1"] [style*="backdrop-filter: blur(24px)"] {
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,0.04),
    0 12px 28px rgba(0,0,0,0.35),
    0 4px 12px rgba(252,76,2,0.08) !important;
}
`;

window.DarkLayer = DarkLayer;
