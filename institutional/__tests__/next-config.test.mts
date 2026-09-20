import { test } from "node:test";
import assert from "node:assert/strict";
import nextConfig from "../next.config.ts";

/**
 * Regression guard for the "Body exceeded 1 MB limit" incident: the
 * volunteer application form (and every other Server Action with a file
 * input — membership, committee registration) submits through a Server
 * Action, which Next.js caps at 1MB by default regardless of what Laravel's
 * own per-file validation allows. A real applicant's photo alone routinely
 * exceeds 1MB, so this silently blocked every real-world submission while
 * every local test (using few-KB fixture files) stayed under the limit and
 * never caught it.
 */
function parseSizeLimit(value: string | number | undefined): number {
  if (typeof value === "number") return value;
  if (typeof value !== "string") return 0;
  const match = value.trim().match(/^(\d+(?:\.\d+)?)\s*(kb|mb|gb)?$/i);
  if (!match) return 0;
  const n = parseFloat(match[1]);
  const unit = (match[2] ?? "").toLowerCase();
  const multiplier = unit === "gb" ? 1024 ** 3 : unit === "mb" ? 1024 ** 2 : unit === "kb" ? 1024 : 1;
  return n * multiplier;
}

test("Server Actions body size limit is raised well above Next.js's 1MB default", () => {
  const limit = nextConfig.experimental?.serverActions?.bodySizeLimit;
  assert.ok(limit, "experimental.serverActions.bodySizeLimit must be set — the 1MB default blocks any real photo upload");
  const bytes = parseSizeLimit(limit);
  // Form validation allows a 5MB photo AND a 5MB CV in the same submission.
  assert.ok(bytes >= 10 * 1024 * 1024, `bodySizeLimit (${limit}) must cover a 5MB photo + 5MB CV plus multipart overhead`);
});
