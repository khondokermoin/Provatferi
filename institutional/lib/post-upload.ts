/**
 * The browser half of an upload route: POST a form's multipart body to a same-origin route handler and read the
 * form's state out of the JSON that comes back. Used by every file-upload form (through useUploadSubmit).
 *
 * It is a plain fetch() on purpose and never a Server Action: a Server Action request carries a `Next-Action`
 * header, and Cloudflare's managed WAF rule for CVE-2025-55183 refuses such a request, with a bare 403, whenever
 * the first MiB of its body happens to contain the bytes `"$F` or `'$F` — which a photo or a PDF does by chance
 * about once per 8 MB. See lib/upload-route.ts.
 *
 * Throws on anything that is not the route's own JSON answer — an error page from an edge or proxy layer, a
 * dropped connection, a timeout, JSON of the wrong shape. The caller shows its generic message and the form stays
 * exactly as the visitor left it.
 */

/** Longer than any legitimate upload over a slow mobile link; shorter than leaving the visitor waiting for ever. */
export const POST_TIMEOUT_MS = 90_000;

export async function postUpload<S>(endpoint: string, formData: FormData, isState: (value: unknown) => value is S): Promise<S> {
  const response = await fetch(endpoint, {
    method: "POST",
    body: formData,
    headers: { Accept: "application/json" },
    credentials: "same-origin",
    cache: "no-store",
    ...(typeof AbortSignal.timeout === "function" ? { signal: AbortSignal.timeout(POST_TIMEOUT_MS) } : {}),
  });
  const json: unknown = await response.json();
  if (!isState(json)) throw new Error("unexpected response");
  return json;
}
