/**
 * Resolution hook for `npm run test:api` only — lets Node's native ESM
 * loader follow this project's extensionless relative imports (e.g.
 * `from "./client"`), which is the convention throughout lib/api/*.ts and
 * matches how @/lib/content is written everywhere else in this codebase.
 * Not used by `next build`/`next dev` — Next's own bundler already resolves
 * these regardless.
 */
export async function resolve(specifier, context, nextResolve) {
  try {
    return await nextResolve(specifier, context);
  } catch (err) {
    if (err.code === "ERR_MODULE_NOT_FOUND" && specifier.startsWith(".")) {
      for (const ext of [".ts", ".mts"]) {
        try {
          return await nextResolve(specifier + ext, context);
        } catch {
          // try the next extension
        }
      }
    }
    throw err;
  }
}
