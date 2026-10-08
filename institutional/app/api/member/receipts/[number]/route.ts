import { getMemberSessionToken } from "@/lib/member-session";
import { handleMemberReceiptGet } from "@/lib/receipt-route";

/**
 * A member's own receipt as a PDF (Membership task 5) — a plain GET route handler, so a link in the dashboard simply opens
 * or downloads it. The session token comes from the member's HttpOnly cookie, here, and goes to Laravel as a Bearer; the
 * number in the path is only ever looked up among that member's own receipts (see lib/receipt-route.ts).
 */
export async function GET(request: Request, context: { params: Promise<{ number: string }> }) {
  const { number } = await context.params;
  return handleMemberReceiptGet(request, number, await getMemberSessionToken());
}
