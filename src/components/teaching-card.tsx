import { BookOpen, Check } from "lucide-react";
import type { Section } from "@/data/shapeContent";

export function TeachingCard({ section }: { section: Section }) {
  return (
    <article className="teaching-card">
      <header className="teaching-header">
        {section.letter && <span className="section-letter">{section.letter}</span>}
        <div><h1>{section.title}</h1><p>{section.eyebrow}</p></div>
      </header>
      {section.scripture && <blockquote><BookOpen size={19} /><p>“{section.scripture.text}”<cite>{section.scripture.reference}</cite></p></blockquote>}
      <div className="teaching-copy">{section.paragraphs?.map((paragraph) => <p key={paragraph}>{paragraph}</p>)}</div>
      {section.bullets && <ul className="teaching-list">{section.bullets.map((item) => <li key={item}><Check size={17} /><span>{item}</span></li>)}</ul>}
    </article>
  );
}
