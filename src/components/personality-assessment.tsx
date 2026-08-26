"use client";

import { personalityPairs } from "@/data/shapeContent";

export function PersonalityAssessment({ values, onChange }: { values: Record<string, string>; onChange: (pair: string, value: string) => void }) {
  return (
    <div>
      <header className="assessment-heading"><h1>Plugging In My Personality</h1><p>Instructions: Select one or the other. There is no right or wrong temperament.</p></header>
      <div className="personality-list">
        {personalityPairs.map((pair) => <fieldset className="personality-pair" key={pair.id}><legend>{pair.prompt}</legend>{[pair.left, pair.right].map((choice) => <button type="button" key={choice.id} aria-pressed={values[pair.id] === choice.id} className={values[pair.id] === choice.id ? "selected" : ""} onClick={() => onChange(pair.id, choice.id)}><span className="radio-dot" /><strong>{choice.label}</strong><small>{choice.description}</small></button>)}</fieldset>)}
      </div>
    </div>
  );
}
