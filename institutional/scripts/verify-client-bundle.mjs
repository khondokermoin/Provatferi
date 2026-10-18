// Runs automatically after every `next build` (package.json "postbuild"), on this machine and on the Hostinger build.
//
// The browser may only ever receive the two public institutional addresses (lib/content.ts: org.email = info@,
// org.supportEmail = support@; admin-erp/docs/EMAIL_ARCHITECTURE.md). This reads every file the build ships to the
// browser (.next/static) and fails the build if it finds:
//   - any other @provatferi.org address (admin@ is the SMTP login and internal mailbox; no-reply@ is a sender only),
//   - the name of a mail credential or of a server-only variable (MAIL_PASSWORD, REVALIDATE_SECRET, LARAVEL_API_URL …),
//     which would mean server code was bundled for the client,
//   - the VALUE of REVALIDATE_SECRET, when the build environment has one. The value itself is never printed.
//   - a volunteer group invite (a WhatsApp or Messenger group address) or the names the application-success proof travels
//     under (`pf_applied`, `success_proof`): the central Messenger group is shown only to a verified applicant, on a page
//     rendered per request (admin-erp/docs/VOLUNTEER_MESSENGER_GROUP.md), so none of this may be in a file every visitor
//     downloads. The invite is admin-managed data; the proof handling is server-only code. Neither value is ever printed.
// Text-only: nothing is executed. Also exported for __tests__/mail-identity.test.mts and __tests__/volunteer-messenger-gating.test.mts.
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import { pathToFileURL } from "node:url";

export const PUBLIC_ADDRESSES = ["info@provatferi.org", "support@provatferi.org"];
const SERVER_ONLY_NAMES = /MAIL_PASSWORD|MAIL_USERNAME|MAIL_HOST|HOSTINGER_MAIL_API_TOKEN|REVALIDATE_SECRET|LARAVEL_API_URL|smtp\.hostinger\.com/g;
const ADDRESS = /[A-Za-z0-9._%+-]+@provatferi\.org/gi;
const GROUP_INVITE = /chat\.whatsapp\.com|\bm\.me\/j\/|messenger\.com\/j\//i;
const SUCCESS_PROOF_NAMES = /pf_applied|success_proof/g;

function* files(dir) {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) yield* files(full);
    else if (/\.(js|mjs|css|json|html|txt)$/.test(entry)) yield full;
  }
}

/** @returns {{ scanned: number, problems: string[] }} */
export function scanClientBundle(staticDir, secrets = {}) {
  const problems = [];
  let scanned = 0;
  const values = Object.entries(secrets).filter(([, v]) => typeof v === "string" && v.length >= 16);
  for (const file of files(staticDir)) {
    scanned++;
    const text = readFileSync(file, "utf8");
    const where = relative(staticDir, file);
    for (const address of new Set((text.match(ADDRESS) ?? []).map((a) => a.toLowerCase()))) {
      if (!PUBLIC_ADDRESSES.includes(address)) problems.push(`${where}: names ${address}, which is not a public address`);
    }
    for (const name of new Set(text.match(SERVER_ONLY_NAMES) ?? [])) problems.push(`${where}: mentions ${name}`);
    if (GROUP_INVITE.test(text)) problems.push(`${where}: contains a volunteer group invite address`);
    for (const name of new Set(text.match(SUCCESS_PROOF_NAMES) ?? [])) problems.push(`${where}: mentions ${name}, which belongs to server-only code`);
    for (const [name, value] of values) {
      if (text.includes(value)) problems.push(`${where}: contains the value of ${name}`);
    }
  }
  return { scanned, problems };
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? "").href) {
  const staticDir = join(process.cwd(), ".next", "static");
  if (!existsSync(staticDir)) {
    console.error("[verify-client-bundle] .next/static not found — did `next build` run?");
    process.exit(1);
  }
  const { scanned, problems } = scanClientBundle(staticDir, { REVALIDATE_SECRET: process.env.REVALIDATE_SECRET });
  if (scanned === 0 || problems.length > 0) {
    if (scanned === 0) console.error("[verify-client-bundle] no client file was scanned");
    for (const p of problems) console.error(`[verify-client-bundle] ${p}`);
    process.exit(1);
  }
  console.log(`[verify-client-bundle] ${scanned} client files: only public addresses, no mail credential or server-only variable, no group invite or success-proof name.`);
}
