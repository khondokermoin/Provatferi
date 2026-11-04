import "server-only";
import { createHmac, randomBytes, timingSafeEqual } from "node:crypto";

/**
 * Tells the ERP who the visitor behind a forwarded request actually is.
 *
 * WHY THIS EXISTS. Every public form is submitted to this server, which forwards it to the ERP. The ERP's client is
 * therefore THIS SERVER, not the person filling in the form, so its rate limits were keyed on one address for every
 * visitor on earth — about five submissions a minute for the whole site. The ERP cannot recover the visitor from a
 * forwarding header either: it trusts those only from Cloudflare's ranges, and we are not Cloudflare. So the one
 * party that does know — this server, which received the browser's own request — says so, and signs it.
 *
 * WHAT MAKES IT UNFORGEABLE. The secret lives only in this server's environment and in the ERP's. It is never sent
 * to a browser, never embedded in a page, and never exposed to client-side code (this module is `server-only`), so
 * a browser posting straight to the ERP has nothing to sign with and its context is simply not verified. The
 * signature covers a canonical string including a timestamp and the action name, so a context cannot be replayed
 * later or against a different endpoint.
 *
 * WHAT IT CARRIES, AND NOTHING MORE: the visitor's IP as Cloudflare reported it to us, and an opaque per-browser id.
 * Both are hashed by the ERP before they reach a rate-limit key, and neither is a tracking identifier — the id is a
 * random value in a first-party, HttpOnly cookie whose only job is to tell two visitors behind one office address
 * apart so they cannot exhaust each other's form allowance.
 */

const VERSION = "1";

/** The cookie that distinguishes two visitors behind one shared address. Not analytics, not advertising. */
const SESSION_COOKIE = "pf_vid";
const SESSION_MAX_AGE_SECONDS = 60 * 60 * 12;

function secrets(): string[] {
  const raw = process.env.PUBLIC_CLIENT_CONTEXT_SECRETS ?? process.env.PUBLIC_CLIENT_CONTEXT_SECRET ?? "";
  // Newest first. Signing uses the first; the ERP accepts any, which is what makes rotation a non-event.
  return raw
    .split(",")
    .map((s) => s.trim())
    .filter((s) => s.length >= 32);
}

/**
 * The visitor's address as seen by THIS server.
 *
 * Here — unlike at the ERP — `cf-connecting-ip` is trustworthy: this request came through Cloudflare from the
 * browser, so Cloudflare set it. It is still treated as untrusted INPUT (validated, length-capped) because a
 * malformed value should be dropped rather than forwarded.
 */
async function clientIp(): Promise<string | null> {
  // Imported lazily, not at module load: this module is reached from the shared API client, which is itself
  // imported by unit tests that run outside Next entirely. A static `next/headers` import made every one of those
  // tests fail to resolve a module they never use.
  const { headers } = await import("next/headers");
  const h = await headers();
  for (const name of ["cf-connecting-ip", "x-real-ip"]) {
    const value = h.get(name)?.trim();
    if (value && looksLikeIp(value)) return value;
  }
  // x-forwarded-for is a list; the left-most entry is the client as the first proxy saw it.
  const forwarded = h.get("x-forwarded-for");
  if (forwarded) {
    const first = forwarded.split(",")[0]?.trim();
    if (first && looksLikeIp(first)) return first;
  }
  return null;
}

function looksLikeIp(value: string): boolean {
  if (value.length > 45) return false;
  // Deliberately permissive but bounded: the ERP validates properly with filter_var and drops anything odd.
  return /^[0-9a-fA-F:.]+$/.test(value);
}

/**
 * The per-browser id, read from the cookie or minted now.
 *
 * Returns the value plus whether it has to be set on the response: a route handler cannot always write cookies, so
 * the caller decides. A visitor with cookies disabled simply has no session id and falls back to the IP dimension.
 */
export async function visitorSession(): Promise<{ id: string; isNew: boolean }> {
  const { cookies } = await import("next/headers");
  const jar = await cookies();
  const existing = jar.get(SESSION_COOKIE)?.value;
  if (existing && /^[A-Za-z0-9_-]{8,64}$/.test(existing)) {
    return { id: existing, isNew: false };
  }

  const id = randomBytes(16).toString("base64url");

  // Persisted HERE, not left to each caller. A fresh id on every request would make the per-visitor limit
  // meaningless — every submission would look like a new person — so the id has to survive between requests or the
  // narrow limit protects nothing. Route handlers and Server Actions may both write cookies; a context where they
  // cannot simply means this visitor is identified by address alone for now, which is a weaker key but never a
  // wrong one.
  try {
    jar.set(visitorSessionCookie(id));
  } catch {
    // Read-only cookie scope (a render rather than an action). Not an error worth reporting: the limiter still has
    // the address dimension and the shared ceiling.
  }

  return { id, isNew: true };
}

export interface ClientContextHeaders {
  [header: string]: string;
}

/**
 * Build the signed headers for one forwarded request.
 *
 * Returns an empty object when no secret is configured — local development and tests then behave exactly like a
 * request with no context, which is the honest default rather than a silent bypass.
 */
export async function clientContextHeaders(action: string): Promise<ClientContextHeaders> {
  const keys = secrets();
  if (keys.length === 0) return {};

  const [ip, session] = await Promise.all([clientIp(), visitorSession()]);
  const timestamp = Math.floor(Date.now() / 1000);

  const payload = { v: VERSION, ts: timestamp, act: action, ip, sid: session.id };
  const canonical = [`v${VERSION}`, String(timestamp), action, ip ?? "", session.id ?? ""].join("\n");
  const signature = createHmac("sha256", keys[0]).update(canonical).digest("hex");

  return {
    "X-Client-Context": Buffer.from(JSON.stringify(payload), "utf8").toString("base64url"),
    "X-Client-Context-Signature": signature,
  };
}

/** The cookie a response should set so the next request from this browser is recognisable. */
export function visitorSessionCookie(id: string) {
  return {
    name: SESSION_COOKIE,
    value: id,
    httpOnly: true,
    sameSite: "lax" as const,
    secure: true,
    path: "/",
    maxAge: SESSION_MAX_AGE_SECONDS,
  };
}

/**
 * Which limiter action a forwarded path belongs to.
 *
 * Kept HERE, in one table, rather than passed in by each call site: five forms each naming their own action is five
 * chances to name it wrong or forget it entirely, and a missing action means the request silently falls back to the
 * shared site-wide bucket — the exact failure this work removes. A path with no entry returns null and sends no
 * context, which is safe by default.
 */
export function actionForPath(path: string): string | null {
  const table: Array<[RegExp, string]> = [
    [/^\/public\/recruitment\/[^/]+\/applications$/, "public.volunteer.submit"],
    [/^\/public\/recruitment\/[^/]+\/application-success$/, "public.application.success"],
    [/^\/public\/membership\/applications$/, "public.membership.submit"],
    [/^\/public\/committee-submissions$/, "public.committee.register"],
    [/^\/public\/committee-submissions\/correction\/[^/]+$/, "public.committee.correct"],
    [/^\/member\/auth\/login$/, "member.auth.login"],
    [/^\/member\/auth\/password\/email$/, "member.auth.password.email"],
    [/^\/member\/auth\/password\/reset$/, "member.auth.password.reset"],
  ];
  for (const [pattern, action] of table) {
    if (pattern.test(path)) return action;
  }
  return null;
}

/**
 * The signed headers for a forwarded path, or none when the path is not a limited action.
 *
 * Deliberately NOT used for ordinary content reads: reading a cookie or a request header during a page render opts
 * that page out of static rendering, and how the public site caches its pages is not this work's business.
 */
export async function clientContextHeadersForPath(path: string): Promise<ClientContextHeaders> {
  const action = actionForPath(path);
  if (action === null) return {};
  try {
    return await clientContextHeaders(action);
  } catch {
    // headers()/cookies() are unavailable outside a request scope. A forwarded request with no context is rate
    // limited as the shared proxy identity, which is worse for the visitor but never wrong.
    return {};
  }
}

/** Exported for the tests: the same comparison the ERP makes, so both sides are known to agree. */
export function verifyForTest(canonical: string, signature: string, secret: string): boolean {
  const expected = createHmac("sha256", secret).update(canonical).digest("hex");
  const a = Buffer.from(expected, "utf8");
  const b = Buffer.from(signature, "utf8");
  return a.length === b.length && timingSafeEqual(a, b);
}
