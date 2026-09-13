"use server";

import { apiPostForm, isRecord } from "@/lib/api/client";

export type MembershipApplicationState =
  | { status: "idle" }
  | { status: "success"; applicationNo: string }
  | { status: "validation"; errors: Record<string, string[]> }
  | { status: "error"; message: string };

function isApplicationCreatedResponse(v: unknown): v is { data: { application_no: string } } {
  return isRecord(v) && isRecord(v.data) && typeof v.data.application_no === "string";
}

/**
 * §7/§41: forwards the browser's own FormData straight to admin-erp's public
 * intake endpoint — no reshaping needed, since the field names here already
 * match what MembershipApplicationController::store() validates. A 422
 * (honeypot tripped, closed season, non-self-apply type, bad photo) surfaces
 * as field-level messages the form re-renders next to the right input,
 * never a generic failure.
 */
export async function submitMembershipApplication(
  _prev: MembershipApplicationState,
  formData: FormData,
): Promise<MembershipApplicationState> {
  const result = await apiPostForm("/api/v1/public/membership/applications", formData, {
    validate: isApplicationCreatedResponse,
  });

  if (result.ok) {
    return { status: "success", applicationNo: result.data.data.application_no };
  }
  if (result.error === "validation") {
    return { status: "validation", errors: result.errors };
  }

  return { status: "error", message: "আবেদন জমা দেওয়া যায়নি — একটু পরে আবার চেষ্টা করুন, অথবা সরাসরি যোগাযোগ করুন।" };
}
