import assert from "node:assert/strict";
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import test from "node:test";

/**
 * A form with a file input must not submit its files through a Server Action (`<form action={fn}>` / useActionState).
 *
 * Measured on production 2026-10-04: every Server Action call carries a `Next-Action` header, and Cloudflare's
 * managed WAF rule "React - Leaking Server Functions" (CVE-2025-55183) refuses, with a bare 403 from the edge,
 * any such request whose first 1 MiB contains the bytes `"$F` or `'$F`. A photo or PDF is binary, so it
 * contains them by chance about once per 8 MB: roughly one upload in nine is refused — always the same file,
 * so a retry cannot help — and nothing in a build, a local run or this app's logs shows it. Nothing here can
 * show the edge's verdict either (scripts/cloudflare-upload-probe.mjs does, against production), so what is
 * pinned is the SOURCE: how each form submits, and the route it submits to.
 *
 * Every form with a file input is one of FORMS: a deliberate hybrid. With JavaScript the form posts its
 * multipart body with fetch() (lib/use-upload-submit.ts -> lib/post-upload.ts) to a plain route handler under
 * app/api/ (guarded by lib/upload-route.ts); the Server Action stays only for the no-JavaScript / not-yet-hydrated
 * native post, which carries no `Next-Action` header. There is no list of "known exposed" forms any more: a new
 * form that has a file input and a Server Action fails the first test until it is built the same way and listed.
 */

interface UploadForm {
  /** components/<file> */
  component: string;
  /** The exact endpoint expression the form hands to useUploadSubmit. */
  endpoint: string;
  /** The route.ts that answers it, under app/api/. */
  route: string[];
  /** What that route hands the request to. */
  handler: string;
  /** The Server Action the form keeps for the no-JavaScript path, and the folder under app/[locale]/(site) whose actions.ts holds it. */
  action: string;
  actionsDir: string[];
  /** The page that renders the form; when the action needs an argument (a token, a slug) THIS file binds it, once, and passes it down. */
  bindsInPage?: { page: string[]; call: string };
}

const FORMS: UploadForm[] = [
  {
    component: "CommitteeRegistrationForm.tsx",
    endpoint: "`/api/committee/register/${encodeURIComponent(token)}`",
    route: ["committee", "register", "[token]"],
    handler: "handleCommitteeRegistrationPost(request, token)",
    action: "submitCommitteeRegistration",
    actionsDir: ["committee", "register", "[token]"],
    bindsInPage: { page: ["committee", "register", "[token]", "page.tsx"], call: "submitCommitteeRegistration.bind(null, token)" },
  },
  {
    component: "CommitteeCorrectionForm.tsx",
    endpoint: "`/api/committee/correct/${encodeURIComponent(token)}`",
    route: ["committee", "correct", "[token]"],
    handler: "handleCommitteeCorrectionPost(request, token)",
    action: "submitCommitteeCorrection",
    actionsDir: ["committee", "register", "correct", "[token]"],
    bindsInPage: { page: ["committee", "register", "correct", "[token]", "page.tsx"], call: "submitCommitteeCorrection.bind(null, token)" },
  },
  {
    component: "MembershipApplicationForm.tsx",
    endpoint: '"/api/membership/apply"',
    route: ["membership", "apply"],
    handler: "handleMembershipApplicationPost(request)",
    action: "submitMembershipApplication",
    actionsDir: ["membership"],
  },
  {
    component: "MemberProfileEditForm.tsx",
    endpoint: '"/api/member/profile"',
    route: ["member", "profile"],
    handler: "handleMemberProfilePost(request, await getMemberSessionToken())",
    action: "saveMemberProfile",
    actionsDir: ["member", "dashboard", "profile"],
  },
  {
    component: "VolunteerApplicationForm.tsx",
    endpoint: "`/api/recruitment/${encodeURIComponent(slug)}/apply`",
    route: ["recruitment", "[slug]", "apply"],
    handler: "handleApplicationPost(request, slug)",
    action: "submitVolunteerApplication",
    actionsDir: ["recruitment", "[slug]", "apply"],
    bindsInPage: { page: ["recruitment", "[slug]", "apply", "page.tsx"], call: "submitVolunteerApplication.bind(null, job.slug)" },
  },
];

const ROOT = new URL("..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1");
const read = (...parts: string[]) => readFileSync(join(ROOT, ...parts), "utf8");
/** Source without comments, so prose about `Next-Action` in a docblock cannot satisfy or fail a check on code. */
const code = (source: string) => source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/(^|[^:"'`])\/\/.*$/gm, "$1");

function* tsxFiles(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) yield* tsxFiles(full);
    else if (entry.endsWith(".tsx")) yield full;
  }
}

const hasFileInput = (source: string) => /type=["']file["']|type:\s*["']file["']/.test(source);
const usesServerAction = (source: string) => /useActionState|<form[^>]*\baction=\{/.test(source);

test("every form that has a file input and a Server Action is a listed hybrid", () => {
  const listed = new Set(FORMS.map((f) => join("components", f.component)));
  const offenders: string[] = [];
  const found = new Set<string>();
  let scanned = 0;
  for (const base of ["app", "components"]) {
    for (const file of tsxFiles(join(ROOT, base))) {
      scanned++;
      const rel = relative(ROOT, file);
      const source = readFileSync(file, "utf8");
      if (hasFileInput(source) && usesServerAction(source)) {
        found.add(rel);
        if (!listed.has(rel)) offenders.push(rel);
      }
    }
  }
  assert.ok(scanned > 50, `scanned only ${scanned} files — the path resolution is wrong, so this test protects nothing`);
  assert.deepEqual(
    offenders,
    [],
    `${offenders.join(", ")} has a file input and a Server Action but is not built as the hybrid: Cloudflare refuses ~1 in 9 file uploads that travel as Server Action requests (see this file's header). Post the FormData with fetch() to a route handler under app/api/ (lib/use-upload-submit.ts) and list the form in FORMS.`,
  );
  assert.deepEqual([...found].sort(), [...listed].sort(), "the list and the codebase disagree: a listed form lost its file input or its no-JavaScript Server Action, or the list is stale");
});

for (const form of FORMS) {
  test(`${form.component}: with JavaScript it posts through useUploadSubmit to ${form.endpoint}, never to its Server Action`, () => {
    const source = read("components", form.component);
    const logic = code(source);

    assert.ok(source.includes(`endpoint: ${form.endpoint}`), `the form must hand useUploadSubmit exactly this endpoint: ${form.endpoint}`);
    assert.match(logic, /useUploadSubmit</);
    assert.match(logic, /onSubmit=\{onSubmit\}/, "the submit handler is the hook's");
    assert.match(logic, /encType="multipart\/form-data"/);
    assert.match(logic, /<fieldset className="form-body" disabled=\{busy\}>/, "fields are parked, not removed, while submitting — what was typed and chosen must survive");
    assert.match(logic, /aria-busy=\{busy \|\| undefined\}/);
    assert.match(logic, /<SubmitControl/, "the shared submit button / status line (brand loader, aria-busy, live region)");
    // The Server Action may only be bound for the native no-JavaScript post — never CALLED with the form's data.
    assert.ok(logic.includes(`useActionState(`), "the no-JavaScript path stays");
    // Where the action comes from: a prop the page bound once (when it needs an argument), or the imported action itself (when it needs none).
    if (form.bindsInPage) {
      assert.match(logic, /useActionState\(action, initialState\)/, "the form uses the action its page hands it");
      assert.match(logic, /action: FormAction</, "the action arrives as a typed prop");
      assert.doesNotMatch(logic, /\.bind\(/, "a client form must never bind a Server Action while rendering — see the test below");
    } else {
      assert.match(logic, new RegExp(`useActionState\\(${form.action},`), "an action that needs no argument is used as it is");
    }
    assert.doesNotMatch(logic, new RegExp(`\\b${form.action}\\(`), `calling ${form.action}() sends a Next-Action request that Cloudflare may refuse when a photo happens to contain \`"$F\``);
    assert.doesNotMatch(logic, /\bfetch\(/, "the form must not hand-roll its own request: the hook owns the transport (and its lock, timeout and error handling)");
  });

  test(`${form.component}: the route that answers it is POST-only and hands the request to the shared handler`, () => {
    const routeFile = join(ROOT, "app", "api", ...form.route, "route.ts");
    assert.ok(existsSync(routeFile), `${routeFile} is missing`);
    const route = code(readFileSync(routeFile, "utf8"));

    assert.match(route, /export async function POST\(/);
    assert.ok(route.includes(form.handler), `the route must call ${form.handler}`);
    assert.doesNotMatch(route, /export\s+(async\s+)?function\s+(GET|PUT|PATCH|DELETE|HEAD|OPTIONS)\b/, "only POST is allowed");
    assert.doesNotMatch(route, /export\s+const\s+(GET|PUT|PATCH|DELETE|HEAD|OPTIONS)\b/, "only POST is allowed");
    assert.doesNotMatch(route, /export\s*\{/, "no re-exported methods");
    assert.doesNotMatch(route, /\bfetch\(|new URL\(|request\.url|searchParams/, "a route names no upstream and reads no URL: the handler owns the one upstream path");
  });

  test(`${form.component}: the no-JavaScript Server Action makes the same upstream call and says the same things as the route`, () => {
    const actions = read("app", "[locale]", "(site)", ...form.actionsDir, "actions.ts");
    assert.match(actions, /^"use server";/);
    assert.ok(actions.includes(`export async function ${form.action}(`), `${form.action} is gone from its actions.ts`);
  });
}

test("a page that needs to bind an argument into a form's Server Action binds it ONCE, in the Server Component, and passes the action down", () => {
  for (const form of FORMS.filter((f) => f.bindsInPage)) {
    const page = read("app", "[locale]", "(site)", ...form.bindsInPage!.page);
    assert.ok(page.includes(form.bindsInPage!.call), `${form.component}: ${form.bindsInPage!.page.join("/")} must contain ${form.bindsInPage!.call}`);
    assert.doesNotMatch(page, /^\s*"use client"/, "the page must be a Server Component");
    assert.match(page, new RegExp(`<${form.component.replace(".tsx", "")}[\\s\\S]*?action=\\{`), `${form.component} must receive the bound action as its action prop`);
  }
});

/**
 * INCIDENT, production 2026-10-04: three forms did `const bound = serverAction.bind(null, token)` INSIDE the client component and
 * handed it to useActionState. `.bind` on a Server Action returns a new function with a new, still-pending bound-arguments promise
 * every time. On a no-JavaScript postback React's server render compares the action's signature, which throws that pending promise
 * (a suspension) — and the retry re-runs the component, binds again, and suspends again, forever: the request never completes and
 * the Node worker burns CPU until it is killed. A GET never reaches that comparison, so nothing in a build or a normal browser run
 * shows it; one hand-made POST to the live volunteer page took provatferi.org down for ~20 minutes. Bind in the Server Component
 * (a bound action that arrives as a prop is a single stable reference), or do not bind at all.
 */
test("no client component binds a Server Action while rendering (a no-JavaScript postback would make the server loop forever)", () => {
  const offenders: string[] = [];
  for (const base of ["app", "components"]) {
    for (const file of tsxFiles(join(ROOT, base))) {
      const source = readFileSync(file, "utf8");
      if (!/^\s*"use client"/.test(source)) continue;
      if (/useActionState|<form[^>]*\baction=\{/.test(code(source)) && /\.bind\(/.test(code(source))) offenders.push(relative(ROOT, file));
    }
  }
  assert.deepEqual(offenders, [], `${offenders.join(", ")} calls .bind() in a client component that uses a Server Action. See the comment above this test: bind in the page (a Server Component) and pass the action down as a prop.`);
});

test("the handlers behind the routes never build an upstream URL from the request (no open proxy)", () => {
  for (const file of ["upload-handlers.ts", "volunteer-application-post.ts"]) {
    const source = code(read("lib", file));
    assert.doesNotMatch(source, /\bfetch\(/, `${file} must not fetch anything itself — upstream calls go through lib/api/*`);
    assert.doesNotMatch(source, /request\.url|searchParams|new URL\(/, `${file} must not read a URL out of the request to decide where to go`);
  }
  // The guard reads request.url only to compare hosts with the Origin header; it never fetches.
  assert.doesNotMatch(code(read("lib", "upload-route.ts")), /\bfetch\(/);
  const handlers = code(read("lib", "upload-handlers.ts"));
  assert.equal((handlers.match(/handleUpload</g) ?? []).length, 4, "each of the four handlers goes through the shared guard");
  assert.equal((handlers.match(/maxBytes: MAX_PHOTO_FORM_BYTES/g) ?? []).length, 4, "each has an explicit body ceiling");
});

test("the browser half is a plain same-origin fetch of a multipart body: no Next-Action header, ever", () => {
  const post = code(read("lib", "post-upload.ts"));
  assert.match(post, /method: "POST"/);
  assert.match(post, /body: formData/, "the FormData is the body as it is: the browser writes the multipart framing and its own Content-Type with a boundary");
  assert.match(post, /credentials: "same-origin"/);
  assert.match(post, /cache: "no-store"/);
  assert.doesNotMatch(post, /next-action/i);
  assert.doesNotMatch(post, /Content-Type/i, "a hand-set Content-Type would drop the multipart boundary");

  const hook = code(read("lib", "use-upload-submit.ts"));
  assert.doesNotMatch(hook, /next-action/i);
  assert.match(hook, /postUpload\(endpoint, formData, isState\)/);
});

test("every upload route stays outside the locale rewrite (a rewrite to /bn/api/... would 404 it)", () => {
  assert.match(read("proxy.ts"), /matcher:\s*\["\/\(\(\?!api\//, "proxy.ts must keep excluding api/");
});

test("the shared form code never touches a Server Action or a server-only module in the browser bundle", () => {
  for (const file of ["use-upload-submit.ts", "post-upload.ts", "form-state.ts", "form-messages.ts"]) {
    const source = code(read("lib", file));
    assert.doesNotMatch(source, /server-only|"use server"|next\/headers/, `${file} is imported by client forms`);
  }
  assert.doesNotMatch(code(read("components", "SubmitControl.tsx")), /server-only|"use server"/);
});
