"use server";

import { redirect } from "next/navigation";
import { memberLogout } from "@/lib/api/member";
import { clearMemberSessionCookie, getMemberSessionToken } from "@/lib/member-session";

export async function logoutMember(): Promise<void> {
  const token = await getMemberSessionToken();
  if (token) {
    await memberLogout(token); // best-effort; the cookie is cleared regardless
  }
  await clearMemberSessionCookie();
  redirect("/member/login");
}
