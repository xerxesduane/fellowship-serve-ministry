"use client";

import { gifts, giftResponseOptions } from "@/data/shapeContent";
import type { GiftResponse } from "@/lib/shapeProfile";

export function GiftAssessment({ responses, onChange }: { responses: Record<string, GiftResponse>; onChange: (id: string, response: GiftResponse) => void }) {
  return (
    <div className="gift-assessment">
      <header className="assessment-heading"><h1>Unwrapping My Gifts</h1><p>For every gift, choose the response that best describes you. Your profile will group all gifts as likely, possible, or unlikely.</p></header>
      <div className="gift-list">
        {gifts.map((gift) => (
          <fieldset className="gift-full-row" key={gift.id}>
            <legend><span><strong>{gift.label}</strong><small>{gift.reference}{gift.alternateName ? ` · Also called ${gift.alternateName}` : ""}</small></span><p>{gift.description}</p></legend>
            <div className="gift-response-grid">
              {giftResponseOptions.map((response) => <button type="button" key={response.id} aria-pressed={responses[gift.id] === response.id} className={responses[gift.id] === response.id ? "selected" : ""} onClick={() => onChange(gift.id, response.id as GiftResponse)}>{response.label}</button>)}
            </div>
          </fieldset>
        ))}
      </div>
    </div>
  );
}
