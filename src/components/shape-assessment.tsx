"use client";

import { useEffect, useMemo, useState } from "react";
import Image from "next/image";
import { ArrowLeft, ArrowRight, Check, Compass, RotateCcw } from "lucide-react";
import {
  abilities, abilitiesSection, availabilitySection, experienceQuestions, experiencesSection,
  heartQuestions, heartSection, journeySteps, personalitySection, startWays,
  spiritualGiftsSection, tableOfContents, welcomeSection, whatIsShapeSection, workExperiences,
} from "@/data/shapeContent";
import { emptyShapeAnswers, type ShapeAnswers } from "@/lib/shapeProfile";
import { GiftAssessment } from "@/components/gift-assessment";
import { MultiSelectField } from "@/components/multi-select-field";
import { PersonalityAssessment } from "@/components/personality-assessment";
import { ShapeProfile } from "@/components/shape-profile";
import { TeachingCard } from "@/components/teaching-card";

const STORAGE_KEY = "fellowship-dubai-shape-v2";
const WORK_OTHER_GROUPS = ["automotive", "business", "computer", "entertainment", "education-field", "medical", "military", "public-civil", "tax-legal", "transportation", "utilities"];

export function ShapeAssessment() {
  const [step, setStep] = useState(0);
  const [answers, setAnswers] = useState<ShapeAnswers>(emptyShapeAnswers);
  const [restored, setRestored] = useState(false);
  const current = journeySteps[step];
  const progress = Math.round((step / (journeySteps.length - 1)) * 100);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      try {
        const saved = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || "null");
        if (saved?.answers) setAnswers({ ...emptyShapeAnswers, ...saved.answers });
        if (typeof saved?.step === "number" && saved.step >= 0 && saved.step < journeySteps.length) setStep(saved.step);
      } catch { /* Saving is a convenience, not a requirement. */ }
      setRestored(true);
    }, 0);
    return () => window.clearTimeout(timer);
  }, []);

  useEffect(() => {
    if (!restored) return;
    try { window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ step, answers })); } catch { /* Private mode may block storage. */ }
  }, [answers, restored, step]);
  useEffect(() => { window.scrollTo({ top: 0, behavior: "smooth" }); }, [step]);

  const selected = (id: string) => answers.selections[id] || [];
  const updateSelections = (id: string, values: string[]) => setAnswers((value) => ({ ...value, selections: { ...value.selections, [id]: values } }));
  const updateText = (id: string, value: string) => setAnswers((state) => ({ ...state, text: { ...state.text, [id]: value } }));
  const validation = useMemo(() => {
    if (current.id === "gifts-assessment" && Object.keys(answers.gifts).length < 18) return "Choose a response for every spiritual gift before continuing.";
    if (current.id === "personality-assessment" && Object.keys(answers.personality).length < 4) return "Choose one response from each personality pairing before continuing.";
    return "";
  }, [answers.gifts, answers.personality, current.id]);
  const goNext = () => { if (!validation) setStep((value) => Math.min(value + 1, journeySteps.length - 1)); };
  const restart = () => { setAnswers(emptyShapeAnswers); setStep(0); try { window.localStorage.removeItem(STORAGE_KEY); } catch {} };

  return (
    <main className="app-shell">
      <div className="ambient ambient-one" /><div className="ambient ambient-two" />
      {current.id !== "profile" && <header className="progress-header no-print"><div className="journey-progress">
        <button className="fd-mark" onClick={() => setStep(0)} aria-label="Return to welcome"><Image src="/fellowship-logo.jpeg" alt="" width={284} height={221} priority /></button>
        <div className="progress-copy"><div className="progress-label"><span>{current.title}</span><span>{progress}%</span></div><div className="progress-track"><div className="progress-fill" style={{ width: `${progress}%` }} /></div><small>Step {step + 1} of {journeySteps.length}</small></div>
        <span className="purpose-mini">KNOW · GROW · GO</span>
      </div></header>}

      <div className={`journey-wrap ${current.id === "profile" ? "profile-wrap" : ""}`}>
        <section className="journey-stage animate-in" key={current.id}>
          {current.id === "welcome" && <Welcome answers={answers} setAnswers={setAnswers} />}
          {current.id === "contents" && <Contents />}
          {current.id === "what-is-shape" && <TeachingCard section={whatIsShapeSection} />}
          {current.id === "gifts-teaching" && <TeachingCard section={spiritualGiftsSection} />}
          {current.id === "gifts-assessment" && <GiftAssessment responses={answers.gifts} onChange={(id, response) => setAnswers((value) => ({ ...value, gifts: { ...value.gifts, [id]: response } }))} />}
          {current.id === "heart-teaching" && <TeachingCard section={heartSection} />}
          {current.id === "heart-roles" && <MultiSelectField label={heartQuestions[0].prompt} help={heartQuestions[0].help} options={heartQuestions[0].options} selected={selected("heart-roles")} onChange={(values) => updateSelections("heart-roles", values)} otherLabel={heartQuestions[0].otherLabel} otherValue={answers.text["heart-roles-other"]} onOtherChange={(value) => updateText("heart-roles-other", value)} />}
          {current.id === "heart-people-causes" && <div className="field-stack"><MultiSelectField label={heartQuestions[1].prompt} help={heartQuestions[1].help} options={heartQuestions[1].options} selected={selected("heart-people")} onChange={(values) => updateSelections("heart-people", values)} otherLabel={heartQuestions[1].otherLabel} otherValue={answers.text["heart-people-other"]} onOtherChange={(value) => updateText("heart-people-other", value)} /><MultiSelectField label={heartQuestions[2].prompt} help={heartQuestions[2].help} options={heartQuestions[2].options} selected={selected("heart-causes")} onChange={(values) => updateSelections("heart-causes", values)} otherLabel={heartQuestions[2].otherLabel} otherValue={answers.text["heart-causes-other"]} onOtherChange={(value) => updateText("heart-causes-other", value)} searchable /></div>}
          {current.id === "abilities-teaching" && <TeachingCard section={abilitiesSection} />}
          {current.id === "abilities-assessment" && <div className="field-stack"><MultiSelectField label="Select the following Abilities that apply to your life!" help="Select every ability that best describes you." options={abilities} selected={selected("abilities")} onChange={(values) => updateSelections("abilities", values)} searchable /><OtherInputs fields={[["ability-languages-other", "Other language"], ["ability-hobbies-other", "Other hobby-related ability"], ["ability-technical-other", "Other technical ability"], ["abilities-other", "Other ability"]]} answers={answers} updateText={updateText} /></div>}
          {current.id === "personality-teaching" && <TeachingCard section={personalitySection} />}
          {current.id === "personality-assessment" && <PersonalityAssessment values={answers.personality} onChange={(pair, value) => setAnswers((state) => ({ ...state, personality: { ...state.personality, [pair]: value } }))} />}
          {current.id === "experiences-teaching" && <TeachingCard section={experiencesSection} />}
          {current.id === "experiences-personal" && <div className="field-stack"><div className="pastoral-note"><p><strong>Your story belongs to you.</strong> Only share what you are comfortable sharing. Your answers are saved in this browser and may appear in the profile you download, copy, email, print, or share.</p></div>{experienceQuestions.slice(0, 3).map((question) => <MultiSelectField key={question.id} label={question.prompt} help={question.help} options={question.options} selected={selected(question.id)} onChange={(values) => updateSelections(question.id, values)} otherLabel={question.otherLabel} otherValue={answers.text[`${question.id}-other`]} onOtherChange={(value) => updateText(`${question.id}-other`, value)} searchable={question.id === "painful-experiences"} />)}</div>}
          {current.id === "experiences-work" && <div className="field-stack"><MultiSelectField label={experienceQuestions[3].prompt} help="Select all the work areas that apply. Expand each category to choose the complete list." options={workExperiences} selected={selected("work-experiences")} onChange={(values) => updateSelections("work-experiences", values)} otherLabel="Other work experience" otherValue={answers.text["work-experiences-other"]} onOtherChange={(value) => updateText("work-experiences-other", value)} searchable /><OtherInputs fields={WORK_OTHER_GROUPS.map((id) => [`work-other-${id}`, `Other ${workExperiences.find((item) => item.id === id)?.label || "work"} experience`])} answers={answers} updateText={updateText} /></div>}
          {current.id === "experiences-ministry" && <div className="field-stack">{experienceQuestions.slice(4).map((question) => <MultiSelectField key={question.id} label={question.prompt} help="Select 1 to 3 of your ministry experiences." options={question.options} selected={selected(question.id)} onChange={(values) => updateSelections(question.id, values)} otherLabel={question.otherLabel} otherValue={answers.text[`${question.id}-other`]} onOtherChange={(value) => updateText(`${question.id}-other`, value)} maxSelections={question.maxSelections} searchable />)}</div>}
          {current.id === "availability" && <Availability answers={answers} updateText={updateText} selected={selected} updateSelections={updateSelections} />}
          {current.id === "start" && <StartWays />}
          {current.id === "profile" && <ShapeProfile answers={answers} onRestart={restart} />}
        </section>

        {current.id !== "profile" && <footer className="journey-actions no-print"><button className="back-button" onClick={() => setStep((value) => Math.max(value - 1, 0))} disabled={step === 0}><ArrowLeft size={18} />Back</button><div>{validation && <p className="validation-message">{validation}</p>}<button className="primary-button" onClick={goNext} disabled={Boolean(validation)}>{step === journeySteps.length - 2 ? "Build My Profile" : "Continue"}<ArrowRight size={18} /></button></div></footer>}
        {current.id !== "profile" && <p className="autosave-note no-print"><Check size={14} /> Progress is saved in this browser. <button onClick={restart}><RotateCcw size={13} />Start over</button></p>}
      </div>
    </main>
  );
}

function Welcome({ answers, setAnswers }: { answers: ShapeAnswers; setAnswers: React.Dispatch<React.SetStateAction<ShapeAnswers>> }) {
  return <div className="welcome-layout"><div className="welcome-brand"><div className="shape-orbit" role="img" aria-label="S.H.A.P.E.: Spiritual Gifts, Heart, Abilities, Personality, and Experiences"><Compass size={42} aria-hidden="true" /><span aria-hidden="true">S</span><span aria-hidden="true">H</span><span aria-hidden="true">A</span><span aria-hidden="true">P</span><span aria-hidden="true">E</span></div><Image className="welcome-logo" src="/fellowship-logo.jpeg" alt="Fellowship Dubai" width={284} height={221} priority /><p>OUR VISION</p><h2>Know Jesus<br />Grow to be like Jesus<br />Go tell the nations about Jesus</h2></div><div><TeachingCard section={welcomeSection} /><fieldset className="profile-fields"><legend>Your profile details</legend><p>These optional details will appear on your completed profile if you choose to download or share it.</p><label><span>Name</span><input value={answers.profile.name} onChange={(event) => setAnswers((value) => ({ ...value, profile: { ...value.profile, name: event.target.value } }))} autoComplete="name" /></label><label><span>Email</span><input type="email" value={answers.profile.email} onChange={(event) => setAnswers((value) => ({ ...value, profile: { ...value.profile, email: event.target.value } }))} autoComplete="email" /></label><label><span>Phone</span><input type="tel" value={answers.profile.phone} onChange={(event) => setAnswers((value) => ({ ...value, profile: { ...value.profile, phone: event.target.value } }))} autoComplete="tel" /></label></fieldset></div></div>;
}

function Contents() {
  return <article className="contents-card"><p className="eyebrow">Your journey map</p><h1>Table of Contents</h1><ol>{tableOfContents.map(([title, page]) => <li key={title}><span>{title}</span><b>{page}</b></li>)}</ol></article>;
}

function OtherInputs({ fields, answers, updateText }: { fields: string[][]; answers: ShapeAnswers; updateText: (id: string, value: string) => void }) {
  return <div className="other-input-grid">{fields.map(([id, label]) => <label className="other-field" key={id}><span>{label}</span><input value={answers.text[id] || ""} onChange={(event) => updateText(id, event.target.value)} placeholder="Type your own response" /></label>)}</div>;
}

function Availability({ answers, updateText, selected, updateSelections }: { answers: ShapeAnswers; updateText: (id: string, value: string) => void; selected: (id: string) => string[]; updateSelections: (id: string, values: string[]) => void }) {
  const hours = availabilitySection.questions![1]; const timing = availabilitySection.questions![2];
  return <div className="field-stack"><TeachingCard section={availabilitySection} /><fieldset className="reflection-fields"><label><span>{availabilitySection.questions![0].prompt}</span><textarea value={answers.text["service-priority"] || ""} onChange={(event) => updateText("service-priority", event.target.value)} placeholder="Reflect briefly…" /></label></fieldset>{hours.type === "single" && <fieldset className="single-field"><legend>{hours.prompt}</legend><div>{hours.options.map((option) => <button type="button" key={option.id} aria-pressed={selected("hours")[0] === option.id} className={selected("hours")[0] === option.id ? "selected" : ""} onClick={() => updateSelections("hours", [option.id])}><span className="radio-dot" />{option.label}</button>)}</div></fieldset>}{timing.type === "multi" && <MultiSelectField label={timing.prompt} options={timing.options} selected={selected("timing")} onChange={(values) => updateSelections("timing", values)} />}</div>;
}

function StartWays() {
  return <article className="start-card"><h1>5 Ways to S.T.A.R.T. to Deepen Your S.H.A.P.E.</h1><div>{startWays.map((way) => <section key={`${way.letter}-${way.title}`}><span>{way.letter}</span><div><h2>{way.title}</h2><p>{way.text}</p>{way.bullets && <ul>{way.bullets.map((book) => <li key={book}>{book}</li>)}</ul>}</div></section>)}</div></article>;
}
