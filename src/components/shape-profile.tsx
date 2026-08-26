"use client";

import { useMemo, useState } from "react";
import { ArrowRight, Clipboard, ExternalLink, Mail, Printer, RotateCcw } from "lucide-react";
import { buildProfile, profileToText, type ShapeAnswers } from "@/lib/shapeProfile";
import { MinistryGiftTable } from "@/components/ministry-gift-table";

export function ShapeProfile({ answers, onRestart }: { answers: ShapeAnswers; onRestart: () => void }) {
  const profile = useMemo(() => buildProfile(answers), [answers]);
  const text = useMemo(() => `${profileToText(answers, profile)}\n\nCURRENT SERVING OPPORTUNITIES\nhttps://fellowshipdubai.churchcenter.com/people/forms/268058`, [answers, profile]);
  const [copied, setCopied] = useState(false);
  const copy = async () => { await navigator.clipboard.writeText(text); setCopied(true); window.setTimeout(() => setCopied(false), 1600); };
  const mailto = `mailto:${encodeURIComponent(answers.profile.email || "")}?subject=${encodeURIComponent(`${answers.profile.name || "My"} S.H.A.P.E. Profile`)}&body=${encodeURIComponent(text)}`;

  return (
    <section className="profile-page">
      <header className="profile-hero">
        <p className="eyebrow">Fellowship Dubai · Complete profile</p>
        <h1>{answers.profile.name ? `${answers.profile.name}’s` : "My"} S.H.A.P.E. Profile</h1>
        <p>A clear starting point for prayer, reflection, and exploring current serving opportunities.</p>
        <div className="profile-contact"><span>{answers.profile.email || "Email not provided"}</span><span>{answers.profile.phone || "Phone not provided"}</span></div>
        <div className="profile-actions no-print"><button onClick={copy}><Clipboard size={17} />{copied ? "Copied" : "Copy My Profile"}</button><button onClick={() => window.print()}><Printer size={17} />Download / Print PDF</button><a href={mailto}><Mail size={17} />Email / Share My Profile</a><a href="https://fellowshipdubai.churchcenter.com/people/forms/268058" target="_blank" rel="noopener noreferrer">Begin Serving <ExternalLink size={16} /></a></div>
      </header>

      <ProfileSection letter="S" title="Spiritual Gifts">
        <GiftGroup label="Likely gifts" values={profile.spiritualGifts.likely} /><GiftGroup label="Possible gifts" values={profile.spiritualGifts.possible} /><GiftGroup label="Unlikely gifts" values={profile.spiritualGifts.unlikely} muted />
      </ProfileSection>
      <ProfileSection letter="H" title="Heart / Passion">
        <ProfileList label="Roles I enjoy" values={profile.heart.roles} /><ProfileList label="People I care about" values={profile.heart.people} /><ProfileList label="Causes I feel led to champion" values={profile.heart.causes} />
      </ProfileSection>
      <ProfileSection letter="A" title="Abilities"><ProfileList label="Abilities I can use" values={profile.abilities} /></ProfileSection>
      <ProfileSection letter="P" title="Personality"><ProfileList label="My personality pattern" values={profile.personality} /></ProfileSection>
      <ProfileSection letter="E" title="Experiences">
        <div className="profile-experience-grid">{Object.entries(profile.experiences).map(([label, values]) => <ProfileList key={label} label={label} values={values} />)}</div>
      </ProfileSection>
      <ProfileSection letter="+" title="Availability">
        <dl className="availability-summary"><div><dt>Are you making service a priority?</dt><dd>{profile.availability.priority}</dd></div><div><dt>Time per week</dt><dd>{profile.availability.hours}</dd></div><div><dt>Best times</dt><dd>{profile.availability.timing.join(", ") || "Not specified"}</dd></div></dl>
      </ProfileSection>
      <article className="recommendations-section">
        <header><p className="eyebrow">Personalized starting points</p><h2>Your top 3 ministry matches</h2><p>These suggestions are based on your likely and possible spiritual gifts. Use them as conversation starters, not a final assignment.</p></header>
        <ol className="ministry-recommendations">{profile.recommendedMinistries.map((item, index) => <li key={item.ministry}><span>{index + 1}</span><div><h3>{item.ministry}</h3><p>{item.matchedGifts.length ? `Strong alignment with ${item.matchedGifts.join(", ")}.` : "A flexible place to explore your S.H.A.P.E. with a ministry leader."}</p></div></li>)}</ol>
      </article>
      <MinistryGiftTable />
      <article className="results-handoff no-print">
        <section className="save-reminder"><div><p className="eyebrow">Before you continue</p><h2>Save your results</h2><p>Serving forms open outside this tool. Save a PDF or email a copy to yourself before you leave so your profile is easy to return to.</p></div><div><button type="button" onClick={() => window.print()}><Printer size={17} />Save / Download PDF</button><a href={mailto}><Mail size={17} />Email My Results</a></div></section>
        <section className="next-step-panel"><header><p className="eyebrow">Ready to take the next step?</p><h2>Choose how you would like to continue</h2></header><div className="next-step-grid"><a href="https://fellowshipdubai.churchcenter.com/people/forms/268058" target="_blank" rel="noopener noreferrer"><strong>Explore Serving Opportunities</strong><span>View current opportunities and tell Fellowship Dubai where you would like to serve. <ArrowRight size={17} /></span></a><a href="https://fellowshipdubai.churchcenter.com/people/forms/268058" target="_blank" rel="noopener noreferrer"><strong>Talk to a S.H.A.P.E. Advisor</strong><span>Open the form and select the SERVE Team to ask for personal guidance. <ArrowRight size={17} /></span></a></div><div className="embedded-form"><div><h3>Serving opportunities form</h3><p>You can complete the form here or open it in a new tab.</p></div><iframe src="https://fellowshipdubai.churchcenter.com/people/forms/268058" title="Fellowship Dubai serving opportunities form" loading="lazy" /><a href="https://fellowshipdubai.churchcenter.com/people/forms/268058" target="_blank" rel="noopener noreferrer">Open the serving form in a new tab <ExternalLink size={16} /></a></div></section>
      </article>
      <div className="profile-footer no-print"><button className="back-button" onClick={onRestart}><RotateCcw size={17} />Start a new profile</button></div>
    </section>
  );
}

function ProfileSection({ letter, title, children }: { letter: string; title: string; children: React.ReactNode }) {
  return <article className="profile-section"><header><span>{letter}</span><h2>{title}</h2></header><div className="profile-section-body">{children}</div></article>;
}
function ProfileList({ label, values }: { label: string; values: string[] }) {
  return <div className="profile-list"><h3>{label}</h3>{values.length ? <ul>{values.map((value) => <li key={value}>{value}</li>)}</ul> : <p>None selected</p>}</div>;
}
function GiftGroup({ label, values, muted }: { label: string; values: string[]; muted?: boolean }) {
  return <div className={`gift-group ${muted ? "muted" : ""}`}><h3>{label}</h3><div>{values.length ? values.map((value) => <span key={value}>{value}</span>) : <p>None selected</p>}</div></div>;
}
