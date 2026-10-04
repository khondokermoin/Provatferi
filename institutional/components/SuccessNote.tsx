"use client";

import { useEffect, useRef, type ReactNode } from "react";

/**
 * The confirmation an upload form shows in place of (or under) itself once Laravel has accepted the submission.
 * Mounting is the moment it matters: the long form it replaces has just collapsed, so the note is brought into view
 * and takes focus — which also has it announced, because the control that held focus (the button, then the status
 * line) is gone. Mount it only while the confirmation is current; each success then mounts a fresh one.
 */
export default function SuccessNote({ children }: { children: ReactNode }) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    ref.current?.scrollIntoView({ behavior: "smooth", block: "center" });
    ref.current?.focus({ preventScroll: true });
  }, []);
  return (
    <div ref={ref} className="callout" role="status" tabIndex={-1}>
      {children}
    </div>
  );
}
