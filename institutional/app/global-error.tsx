"use client";

import { useEffect } from "react";

// The last-resort boundary: only fires when the ROOT layout itself throws
// (a font load failure, a provider crash) — anything (site)/error.tsx would
// normally catch never reaches here. Per Next.js's own requirement, this
// replaces the entire document, so it renders its own <html>/<body> and
// cannot assume globals.css, fonts or any provider from layout.tsx ran —
// everything it needs is inline here, deliberately with no external
// dependency that could itself be the thing that failed.

export default function GlobalError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  useEffect(() => {
    // eslint-disable-next-line no-console
    console.error("Root layout error:", error.digest ?? error.message, error);
  }, [error]);

  return (
    <html lang="bn">
      <body
        style={{
          margin: 0,
          minHeight: "100vh",
          display: "flex",
          alignItems: "center",
          justifyContent: "center",
          fontFamily:
            "'Noto Sans Bengali', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
          background: "#fbf9f4",
          color: "#33261c",
        }}
      >
        <div style={{ textAlign: "center", maxWidth: 420, padding: "0 20px" }}>
          <p style={{ fontSize: 40, margin: "0 0 8px", color: "#ac350a" }} aria-hidden="true">
            ⚠
          </p>
          <h1 style={{ fontSize: 22, margin: "0 0 10px", color: "#1c2430" }}>সাইটটি লোড করা যায়নি</h1>
          <p style={{ fontSize: 15, lineHeight: 1.6, margin: "0 0 20px", color: "#6b6053" }}>
            একটি গুরুতর সমস্যা হয়েছে। পাতাটি রিফ্রেশ করে আবার চেষ্টা করুন।
          </p>
          <button
            type="button"
            onClick={() => reset()}
            style={{
              fontFamily: "inherit",
              fontSize: 15,
              padding: "10px 22px",
              borderRadius: 8,
              border: "none",
              background: "#ac350a",
              color: "#fff",
              cursor: "pointer",
            }}
          >
            আবার চেষ্টা করুন
          </button>
          {error.digest ? (
            <p style={{ fontSize: 12, color: "#a89f94", marginTop: 18 }}>তথ্যসূত্র: {error.digest}</p>
          ) : null}
        </div>
      </body>
    </html>
  );
}
