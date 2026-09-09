#!/usr/bin/env node
/**
 * Uploads a release built by build-release.sh to production, via TUS, into
 * a staging path under the PUBLIC docroot — this is the same upload
 * mechanism used throughout this project (Hostinger has no SSH; the file
 * browser's TUS API and cron-as-shell are the only two primitives). The
 * staging path is temporary: remote/release-manager.php's `stage` command
 * moves the extracted files into the private laravel-admin-releases/ tree
 * (a sibling of laravel-admin/, never under public_html) and deletes the
 * staging copy the same run.
 *
 * Usage: node deploy/upload-release.mjs <release-dir> <hostinger-username>
 *
 * Requires Hostinger upload credentials as environment variables
 * (HOSTINGER_UPLOAD_URL, HOSTINGER_AUTH_KEY, HOSTINGER_AUTH_REST) — this
 * script never hardcodes them, since they're short-lived tokens generated
 * per-session by the hosting-hosting_generateUploadURLV1 tool.
 */
import { readFileSync, statSync } from "node:fs";
import { basename } from "node:path";

const [, , releaseDir] = process.argv;
if (!releaseDir) {
  console.error("Usage: node upload-release.mjs <release-dir>");
  process.exit(2);
}

const url = process.env.HOSTINGER_UPLOAD_URL;
const authKey = process.env.HOSTINGER_AUTH_KEY;
const authRest = process.env.HOSTINGER_AUTH_REST;
if (!url || !authKey || !authRest) {
  console.error("Set HOSTINGER_UPLOAD_URL, HOSTINGER_AUTH_KEY, HOSTINGER_AUTH_REST (from generateUploadURLV1) first.");
  process.exit(2);
}

const manifest = JSON.parse(readFileSync(`${releaseDir}/manifest.json`, "utf8"));
const releaseId = `${manifest.commit_short}-${manifest.built_at.replace(/[:T]/g, "").slice(0, 15)}`;
const stagingPrefix = `_release_staging/${releaseId}`;

async function uploadFile(localPath, remoteName) {
  const size = statSync(localPath).size;
  const dest = `${stagingPrefix}/${remoteName}`;

  const createRes = await fetch(`${url}/${dest}?override=true`, {
    method: "POST",
    headers: { "X-Auth": authKey, "X-Auth-Rest": authRest, "Tus-Resumable": "1.0.0", "Upload-Length": String(size), "Upload-Offset": "0" },
  });
  if (createRes.status !== 201) throw new Error(`create failed for ${remoteName}: ${createRes.status}`);

  const body = readFileSync(localPath);
  const patchRes = await fetch(`${url}/${dest}?override=true`, {
    method: "PATCH",
    headers: {
      "X-Auth": authKey,
      "X-Auth-Rest": authRest,
      "Tus-Resumable": "1.0.0",
      "Content-Type": "application/offset+octet-stream",
      "Upload-Offset": "0",
    },
    body,
  });
  if (patchRes.status !== 204) throw new Error(`patch failed for ${remoteName}: ${patchRes.status}`);

  console.log(`uploaded: ${remoteName} (${(size / 1024).toFixed(1)} KB) -> ${dest}`);
  return dest;
}

console.log(`Release: ${releaseId} (commit ${manifest.commit})`);
await uploadFile(`${releaseDir}/manifest.json`, "manifest.json");
await uploadFile(`${releaseDir}/private.tar`, "private.tar");
await uploadFile(`${releaseDir}/public-assets.tar`, "public-assets.tar");

console.log();
console.log(`Staged at public_html/admin/${stagingPrefix}/`);
console.log(`Release ID: ${releaseId}`);
console.log(`Next: run remote/release-manager.php stage ${releaseId}  (via cron, per deploy/README.md)`);
