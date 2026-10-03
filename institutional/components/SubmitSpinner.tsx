/**
 * The "working…" mark for a submit button. The same inline-SVG idiom as the rest of the site's
 * icons (viewBox 24, stroke = currentColor, aria-hidden), so it takes the button's own colour in
 * either theme; the spin itself lives in globals.css and is switched off for visitors who ask
 * for reduced motion (the arc is still a clear "busy" mark standing still, and the button's label
 * says what is happening in words).
 */
export default function SubmitSpinner() {
  return (
    <svg className="submit-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" aria-hidden="true" focusable="false">
      <circle cx="12" cy="12" r="9" opacity="0.25" />
      <path d="M21 12a9 9 0 0 0-9-9" />
    </svg>
  );
}
