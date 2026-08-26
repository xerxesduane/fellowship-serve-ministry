import { Compass } from "lucide-react";
import { ministryGiftTable } from "@/data/ministryGiftTable";

export function MinistryGiftTable() {
  return (
    <article className="ministry-table-section">
      <header className="ministry-table-heading">
        <div className="ministry-table-icon"><Compass size={26} aria-hidden="true" /></div>
        <div>
          <p className="eyebrow">Ministry guide</p>
          <h2>Explore more places where your gifts may contribute.</h2>
          <p>This table is a reflective guide, not a prescription. Prayerfully consider where your gifts may align, while giving priority to personal conviction and the Holy Spirit’s leading.</p>
        </div>
      </header>

      <div className="ministry-table-desktop">
        <table>
          <caption className="sr-only">Fellowship Dubai ministry and spiritual gift guide</caption>
          <thead><tr><th scope="col">Ministry</th><th scope="col">Spiritual gifts that strengthen it</th></tr></thead>
          <tbody>
            {ministryGiftTable.map((row) => <tr key={row.ministry}><th scope="row">{row.ministry}</th><td>{row.gifts.join(", ")}</td></tr>)}
          </tbody>
        </table>
      </div>

      <div className="ministry-table-mobile">
        {ministryGiftTable.map((row) => (
          <section key={row.ministry}>
            <h3>{row.ministry}</h3>
            <p>{row.gifts.join(", ")}</p>
          </section>
        ))}
      </div>
    </article>
  );
}
