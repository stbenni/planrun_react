import { CATS, catById } from './catalog';

export default function SettingsDesktopV3({ ctx }) {
  const { activeCat, setCat } = ctx;
  const current = catById(activeCat) || CATS[0];
  const Section = current.Component;

  return (
    <div className="sv3 sv3-desk">
      <div className="sv3-desk-body">
        <aside className="sv3-rail">
          {CATS.map((c) => (
            <button key={c.id} type="button"
              className={`sv3-rail-item ${current.id === c.id ? 'sv3-rail-item--on' : ''}`}
              onClick={() => setCat(c.id)}>
              <span className="sv3-rail-ic"><c.Icon /></span>
              <span className="sv3-rail-label">{c.title}</span>
            </button>
          ))}
        </aside>

        <main className="sv3-desk-content">
          <h1 className="sv3-desk-h1">{current.title}</h1>
          <Section ctx={ctx} />
        </main>
      </div>
    </div>
  );
}
