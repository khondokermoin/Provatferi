"use client";

import { createContext, useContext } from "react";
import { fallbackContact, type PublicContact } from "@/lib/contact-resolve";
import type { Locale } from "@/lib/i18n";

/**
 * Hands the contact details the SERVER fetched (app/[locale]/(site)/layout.tsx, from the ERP's public settings) to the
 * client components that cannot fetch: the Header, the Footer and the segment's error boundary. The value is a plain
 * serialisable object, resolved once per request; nothing here talks to the ERP.
 */
const ContactContext = createContext<PublicContact | null>(null);

export function ContactProvider({ value, children }: { value: PublicContact; children: React.ReactNode }) {
  return <ContactContext.Provider value={value}>{children}</ContactContext.Provider>;
}

/**
 * The contact details for the page being shown. Outside a provider (the site layout always supplies one, so only a
 * component rendered somewhere unexpected) it is the original values, never an error.
 */
export function usePublicContact(locale: Locale): PublicContact {
  return useContext(ContactContext) ?? fallbackContact(locale);
}
