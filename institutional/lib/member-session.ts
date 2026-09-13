import "server-only";
import { cookies } from "next/headers";

/**
 * §12: the Sanctum token lives ONLY in this Next.js app's own HttpOnly
 * cookie — never in localStorage, never readable by client-side JS, never
 * sent to the browser at all beyond the Set-Cookie header itself. The
 * browser talks only to this app; this app talks to admin.provatferi.org
 * server-side, attaching the token as a Bearer header (see lib/api/member.ts).
 */
const COOKIE_NAME = "member_session";
// A stricter cap than Sanctum's own token lifetime (unset — tokens don't
// expire server-side) — the cookie itself times out an idle browser session
// after 30 days; logging out (or a fresh login) is still what actually
// revokes the underlying token.
const COOKIE_MAX_AGE_SECONDS = 60 * 60 * 24 * 30;

export async function setMemberSessionCookie(token: string): Promise<void> {
  const store = await cookies();
  store.set(COOKIE_NAME, token, {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    path: "/",
    maxAge: COOKIE_MAX_AGE_SECONDS,
  });
}

export async function getMemberSessionToken(): Promise<string | null> {
  const store = await cookies();
  return store.get(COOKIE_NAME)?.value ?? null;
}

export async function clearMemberSessionCookie(): Promise<void> {
  const store = await cookies();
  store.delete(COOKIE_NAME);
}
