import "server-only";
import { getMemberReceiptPdf, RECEIPT_NUMBER_PATTERN } from "./api/member";

/**
 * A member's receipt as a PDF, delivered through this site (Membership task 5; the route file under app/api/member/receipts
 * is a thin wrapper). The browser asks THIS site; this site asks admin-erp with the member's session token — read from the
 * HttpOnly cookie by the route file, never from the URL, never visible to the page — and passes the PDF on.
 *
 * Whose receipt it is, is Laravel's decision and only Laravel's: it looks the number up among that member's own receipts, so
 * another member's receipt number answers exactly like one that does not exist (404) — nothing here can tell them apart,
 * and nothing here reveals that a receipt exists. A signed-out member (no cookie, a revoked or suspended account) is sent
 * to the login page. The PDF is never kept: no cache anywhere (`private, no-store`), no file written.
 */

const NO_STORE = "private, no-store";

function notFound(): Response {
  return new Response("রসিদটি পাওয়া যায়নি।", {
    status: 404,
    headers: { "Content-Type": "text/plain; charset=utf-8", "Cache-Control": NO_STORE, "X-Content-Type-Options": "nosniff" },
  });
}

function toLogin(): Response {
  // A relative Location resolves against the address the visitor actually used — whatever proxy sits in front of this server.
  return new Response(null, { status: 303, headers: { Location: "/member/login", "Cache-Control": NO_STORE } });
}

export async function handleMemberReceiptGet(request: Request, receiptNo: string, sessionToken: string | null): Promise<Response> {
  // Not a receipt number: no upstream call at all.
  if (!RECEIPT_NUMBER_PATTERN.test(receiptNo)) return notFound();
  if (!sessionToken) return toLogin();

  const params = new URL(request.url).searchParams;
  const lang = params.get("lang") === "en" ? "en" : "bn";
  const disposition = params.get("disposition") === "inline" ? "inline" : "attachment";

  const result = await getMemberReceiptPdf(sessionToken, receiptNo, { lang, disposition });
  if (!result.ok) {
    if (result.status === 401 || result.status === 403) return toLogin();
    if (result.status === 404) return notFound();
    return new Response("রসিদ এখন দেখানো যাচ্ছে না। কিছুক্ষণ পরে আবার চেষ্টা করুন।", {
      status: 502,
      headers: { "Content-Type": "text/plain; charset=utf-8", "Cache-Control": NO_STORE },
    });
  }

  if (!(result.response.headers.get("content-type") ?? "").startsWith("application/pdf")) {
    await result.response.body?.cancel();
    return new Response("রসিদ এখন দেখানো যাচ্ছে না।", { status: 502, headers: { "Content-Type": "text/plain; charset=utf-8", "Cache-Control": NO_STORE } });
  }

  return new Response(result.response.body, {
    status: 200,
    headers: {
      "Content-Type": "application/pdf",
      // The file name is built here from the validated number — never copied from the upstream header.
      "Content-Disposition": `${disposition}; filename="${receiptNo}.pdf"`,
      "Cache-Control": NO_STORE,
      "X-Content-Type-Options": "nosniff",
      "X-Robots-Tag": "noindex, nofollow",
    },
  });
}
