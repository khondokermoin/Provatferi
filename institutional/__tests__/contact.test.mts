import assert from "node:assert/strict";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import test, { after, before, mock } from "node:test";
import { getSettings, SETTINGS_CACHE_TAG } from "../lib/api/settings.ts";
import type { SettingsResponse } from "../lib/api/types.ts";
import { getPublicContact } from "../lib/contact.ts";
import { fallbackContact, resolveContact } from "../lib/contact-resolve.ts";
import { org } from "../lib/content.ts";
import { address as addressEn } from "../lib/content.en.ts";

/**
 * What the site shows as contact details, social links and location (admin-erp's Settings screen is the source of
 * truth; docs/PUBLIC_CONTACT_SETTINGS.md). The rule pinned hardest: the original values in lib/content.ts are an OUTAGE
 * fallback and never override what the ERP returned. A current ERP is recognised by the `social` key in its response
 * (possibly empty); from an older ERP or no ERP at all the original values are used.
 */

const ROOT = new URL("..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1");

/** What a CURRENT ERP returns, with the live values; override any part. */
function current(overrides: Partial<{ contact: Partial<SettingsResponse["contact"]>; social: Array<{ platform: string; url: string }>; location: SettingsResponse["location"] }> = {}): SettingsResponse {
  return {
    organization: { name_bn: null, name_en: null, short_name: null, acronym: null, tagline: null },
    contact: {
      email: "info@provatferi.org",
      support_email: "support@provatferi.org",
      phone: "+8801625050408",
      address: "লেবাশ, চান্দিনা, কুমিল্লা",
      address_en: "Lebash, Chandina, Cumilla",
      facebook_url: "https://fb.com/provatfericenter",
      ...overrides.contact,
    },
    social: overrides.social ?? [{ platform: "facebook", url: "https://fb.com/provatfericenter" }],
    location: overrides.location ?? null,
    seo: { title: null, description: null, alternate_names: [], canonical_url: null },
    links: { website_url: null, literature_url: null },
  };
}

/** What the ERP returned before the admin-managed contact settings existed: no social, support_email, address_en or location. */
function legacy(contact: Partial<SettingsResponse["contact"]> = {}): SettingsResponse {
  const { social: _social, location: _location, ...rest } = current({ contact });
  void _social;
  void _location;
  const { support_email: _s, address_en: _a, ...legacyContact } = rest.contact;
  void _s;
  void _a;
  return { ...rest, contact: legacyContact as SettingsResponse["contact"] };
}

// --- the ERP's values win ---------------------------------------------------------------------------------------------

test("every value the ERP returns is what the site shows, not the original", () => {
  const settings = current({
    contact: { email: "office@example.org", support_email: "help@example.org", phone: "+880 1711 112222", address: "নতুন ঠিকানা", address_en: "A new address" },
    social: [{ platform: "linkedin", url: "https://www.linkedin.com/company/provatferi" }],
  });

  const bn = resolveContact(settings, "bn");
  const en = resolveContact(settings, "en");

  assert.equal(bn.email, "office@example.org");
  assert.equal(bn.supportEmail, "help@example.org");
  assert.equal(bn.phone, "+880 1711 112222");
  assert.equal(bn.phoneHref, "tel:+8801711112222");
  assert.equal(bn.address, "নতুন ঠিকানা");
  assert.equal(en.address, "A new address");
  assert.equal(en.addressIsFallback, false);
  assert.deepEqual(bn.social, [{ platform: "linkedin", url: "https://www.linkedin.com/company/provatferi" }]);
  assert.equal(bn.source, "erp");
  for (const value of [bn.email, bn.supportEmail, bn.phone, bn.address, en.address]) {
    assert.notEqual(value, org.email);
    assert.notEqual(value, org.phone);
  }
});

test("Bangla and English each get their own address", () => {
  const settings = current();

  assert.equal(resolveContact(settings, "bn").address, "লেবাশ, চান্দিনা, কুমিল্লা");
  assert.equal(resolveContact(settings, "en").address, "Lebash, Chandina, Cumilla");
  assert.equal(resolveContact(settings, "bn").addressLang, "bn");
  assert.equal(resolveContact(settings, "en").addressLang, "en");
});

test("an English address that was never entered shows the Bangla one with the translation note, never the old English text", () => {
  const en = resolveContact(current({ contact: { address: "নতুন ঠিকানা", address_en: null } }), "en");

  assert.equal(en.address, "নতুন ঠিকানা");
  assert.equal(en.addressIsFallback, true);
  assert.equal(en.addressLang, "bn");
  assert.notEqual(en.address, addressEn, "the approved English text may no longer describe the new address");

  const blank = resolveContact(current({ contact: { address_en: "   " } }), "en");
  assert.equal(blank.addressIsFallback, true);
});

test("the Bangla page never shows the English address and needs no translation note", () => {
  const bn = resolveContact(current({ contact: { address: "বাংলা ঠিকানা", address_en: "English address" } }), "bn");

  assert.equal(bn.address, "বাংলা ঠিকানা");
  assert.equal(bn.addressIsFallback, false);
});

// --- a current ERP is authoritative: removed means removed --------------------------------------------------------------

test("switching every social link off removes them: the original Facebook link is not put back", () => {
  const contact = resolveContact(current({ social: [], contact: { facebook_url: null } }), "bn");

  assert.deepEqual(contact.social, []);
  assert.notEqual(org.facebook, "", "the original exists, and still must not appear");
});

test("a phone number the admin removed is removed, not restored from the original", () => {
  const contact = resolveContact(current({ contact: { phone: null } }), "en");

  assert.equal(contact.phone, null);
  assert.equal(contact.phoneHref, null);
});

test("an address the admin removed is removed too", () => {
  const contact = resolveContact(current({ contact: { address: null, address_en: null } }), "en");

  assert.equal(contact.address, null);
  assert.equal(contact.addressIsFallback, false);
});

test("social links come out in a fixed order, once each, and only for known platforms", () => {
  const contact = resolveContact(
    current({
      social: [
        { platform: "youtube", url: "https://www.youtube.com/@provatferi" },
        { platform: "tiktok", url: "https://www.tiktok.com/@provatferi" },
        { platform: "facebook", url: "https://www.facebook.com/provatfericenter" },
        { platform: "facebook", url: "https://www.facebook.com/another" },
        { platform: "linkedin", url: "https://www.linkedin.com/company/provatferi" },
      ],
    }),
    "bn",
  );

  assert.deepEqual(
    contact.social.map((link) => link.platform),
    ["facebook", "linkedin", "youtube"],
  );
  assert.equal(contact.social[0].url, "https://www.facebook.com/provatfericenter", "the first entry of a platform wins");
});

// --- outage and old ERP: the original values --------------------------------------------------------------------------

test("with no ERP at all (an outage) the site shows the original values, in both languages", () => {
  const bn = fallbackContact("bn");
  const en = fallbackContact("en");

  assert.equal(bn.source, "fallback");
  assert.equal(bn.email, org.email);
  assert.equal(bn.supportEmail, org.supportEmail);
  assert.equal(bn.phone, org.phone);
  assert.equal(bn.address, org.address);
  assert.equal(en.address, addressEn);
  assert.equal(en.addressIsFallback, false);
  assert.deepEqual(bn.social, [{ platform: "facebook", url: org.facebook }]);
  assert.equal(bn.map, null);
});

test("an ERP that predates the admin-managed settings keeps working: its own values where it has them, the original where it has none", () => {
  const old = resolveContact(legacy({ email: "office@example.org", phone: null }), "en");

  assert.equal(old.source, "fallback");
  assert.equal(old.email, "office@example.org", "what an older ERP does send is used");
  assert.equal(old.phone, org.phone, "what it does not send falls back");
  assert.equal(old.supportEmail, org.supportEmail);
  assert.equal(old.address, addressEn, "no English address in an older ERP: the approved English text, no translation note");
  assert.equal(old.addressIsFallback, false);
  assert.deepEqual(old.social, [{ platform: "facebook", url: "https://fb.com/provatfericenter" }]);
});

// --- hostile or unpublishable values never reach a page ---------------------------------------------------------------

test("an unpublishable e-mail address from the ERP is replaced by the original role address, never shown", () => {
  for (const bad of ["admin@provatferi.org", "no-reply@provatferi.org", "security@provatferi.org", "not an address", "info@provatferi.org\r\nBcc: x@y.z"]) {
    const contact = resolveContact(current({ contact: { email: bad, support_email: bad } }), "bn");

    assert.equal(contact.email, org.email, bad);
    assert.equal(contact.supportEmail, org.supportEmail, bad);
  }
});

test("a hostile social link from the ERP is dropped", () => {
  const contact = resolveContact(
    current({
      social: [
        { platform: "facebook", url: "javascript:alert(1)" },
        { platform: "linkedin", url: "https://evil.example/company/provatferi" },
        { platform: "youtube", url: "http://www.youtube.com/@provatferi" },
      ],
    }),
    "bn",
  );

  assert.deepEqual(contact.social, []);
});

test("a hostile phone number is dropped, and the tel: link is digits only", () => {
  assert.equal(resolveContact(current({ contact: { phone: "<script>alert(1)</script>" } }), "bn").phone, null);
  assert.equal(resolveContact(current({ contact: { phone: "+880 (1625) 050-408" } }), "bn").phoneHref, "tel:+8801625050408");
});

test("a map location is shown only for a genuine Google Maps link, with the label of the page's language", () => {
  const settings = current({ location: { map_url: "https://maps.app.goo.gl/AbCdEf12345", label: "বাংলা নাম", label_en: "English name" } });

  assert.deepEqual(resolveContact(settings, "bn").map, { url: "https://maps.app.goo.gl/AbCdEf12345", label: "বাংলা নাম", labelLang: "bn" });
  assert.deepEqual(resolveContact(settings, "en").map, { url: "https://maps.app.goo.gl/AbCdEf12345", label: "English name", labelLang: "en" });
  assert.deepEqual(
    resolveContact(current({ location: { map_url: "https://maps.app.goo.gl/AbCdEf12345", label: "বাংলা নাম", label_en: null } }), "en").map,
    { url: "https://maps.app.goo.gl/AbCdEf12345", label: "বাংলা নাম", labelLang: "bn" },
    "no English label: the Bangla one, marked as Bangla",
  );
  assert.equal(resolveContact(current({ location: { map_url: "https://evil.example/maps/place/x", label: null, label_en: null } }), "bn").map, null);
  assert.equal(resolveContact(current({ location: { map_url: "https://www.google.com/maps/embed?pb=x", label: null, label_en: null } }), "bn").map, null);
  assert.equal(resolveContact(current(), "bn").map, null, "no map configured, no map action");
});

// --- the whole path: fetch -> guard -> resolve ------------------------------------------------------------------------

before(() => {
  process.env.LARAVEL_API_URL = "https://admin.example.test";
});

after(() => {
  globalThis.fetch = undefined as unknown as typeof fetch;
});

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

test("getPublicContact reads the ERP, caches under the site-settings tag, and returns the ERP's values", async () => {
  const fetchMock = mock.fn(async () => jsonResponse({ data: current({ contact: { phone: "+8801711112222" } }) }));
  globalThis.fetch = fetchMock as unknown as typeof fetch;

  const contact = await getPublicContact("bn");

  assert.equal(contact.phone, "+8801711112222");
  assert.equal(contact.source, "erp");
  const [url, init] = fetchMock.mock.calls[0].arguments as unknown as [string, { next?: { revalidate?: number; tags?: string[] } }];
  assert.equal(url, "https://admin.example.test/api/v1/settings");
  assert.deepEqual(init.next?.tags, [SETTINGS_CACHE_TAG], "the ERP's purge call can only reach this fetch through its tag");
  assert.equal(SETTINGS_CACHE_TAG, "site-settings");
  assert.equal(init.next?.revalidate, 300, "the safety-net window if the purge call is ever lost");
});

test("getPublicContact never throws: a network error, an HTTP error or a malformed body gives the original values", async () => {
  for (const failing of [
    async () => {
      throw new TypeError("fetch failed");
    },
    async () => jsonResponse({ message: "boom" }, 500),
    async () => jsonResponse({ data: { contact: "not an object" } }),
    async () => new Response("<html>not json</html>", { status: 200 }),
  ]) {
    globalThis.fetch = mock.fn(failing) as unknown as typeof fetch;
    const contact = await getPublicContact("en");

    assert.equal(contact.source, "fallback");
    assert.equal(contact.email, org.email);
    assert.equal(contact.address, addressEn);
  }
});

test("the settings guard accepts the old response shape and the new one, and rejects a malformed new section", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: legacy() })) as unknown as typeof fetch;
  assert.equal((await getSettings()).ok, true, "an older ERP's response");

  globalThis.fetch = mock.fn(async () => jsonResponse({ data: current() })) as unknown as typeof fetch;
  assert.equal((await getSettings()).ok, true, "the current response");

  for (const broken of [{ social: "facebook" }, { social: [{ platform: 1, url: "x" }] }, { social: [null] }, { location: "map" }, { location: { map_url: 5 } }, { contact: { ...current().contact, support_email: 5 } }]) {
    globalThis.fetch = mock.fn(async () => jsonResponse({ data: { ...current(), ...broken } })) as unknown as typeof fetch;
    assert.equal((await getSettings()).ok, false, JSON.stringify(broken));
  }
});

// --- the site consumes the ERP rather than hard-coded values -----------------------------------------------------------

function* sources(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      if (entry !== "__tests__") yield* sources(full);
    } else if (/\.(tsx?|mts)$/.test(entry)) {
      yield full;
    }
  }
}

test("no page or component reads the hard-coded contact values: they come from getPublicContact / usePublicContact", () => {
  const offenders: string[] = [];
  let scanned = 0;
  for (const base of ["app", "components", "lib"]) {
    for (const file of sources(join(ROOT, base))) {
      scanned++;
      const rel = relative(ROOT, file).replace(/\\/g, "/");
      if (rel === "lib/content.ts" || rel === "lib/contact-resolve.ts") continue; // the outage fallback lives here and only here
      // Comments may mention the old names (content.en.ts does); only code counts.
      const code = readFileSync(file, "utf8").replace(/\/\*[\s\S]*?\*\//g, "").replace(/(^|\s)\/\/.*$/gm, "$1");
      for (const m of code.matchAll(/\borg\.(email|phone|facebook|address|supportEmail)\b/g)) offenders.push(`${rel}: ${m[0]}`);
    }
  }

  assert.ok(scanned > 50, `scanned only ${scanned} files: the path is wrong, so this protects nothing`);
  assert.deepEqual(offenders, [], "a hard-coded contact value would be stale the moment an admin changes it");
});

test("the surfaces that show contact details are wired to the single source", () => {
  const read = (...parts: string[]) => readFileSync(join(ROOT, ...parts), "utf8");

  for (const file of [["app", "[locale]", "(site)", "contact", "page.tsx"], ["app", "[locale]", "(site)", "membership", "page.tsx"], ["app", "[locale]", "(site)", "events", "page.tsx"], ["app", "[locale]", "layout.tsx"], ["app", "[locale]", "(site)", "layout.tsx"]]) {
    assert.match(read(...file), /getPublicContact/, file.join("/"));
  }
  for (const file of [["components", "Header.tsx"], ["components", "Footer.tsx"], ["app", "[locale]", "(site)", "error.tsx"]]) {
    assert.match(read(...file), /usePublicContact/, file.join("/"));
  }
  assert.match(read("app", "[locale]", "(site)", "layout.tsx"), /<ContactProvider value=\{contact\}>/);
  assert.match(read("lib", "api", "settings.ts"), /tags: \[SETTINGS_CACHE_TAG\]/);
});

test("no URL of the organisation's own social pages is written anywhere but lib/content.ts", () => {
  const offenders: string[] = [];
  for (const base of ["app", "components", "lib"]) {
    for (const file of sources(join(ROOT, base))) {
      const rel = relative(ROOT, file).replace(/\\/g, "/");
      if (rel === "lib/content.ts") continue;
      if (/fb\.com\/provatfericenter|facebook\.com\/provatferi/.test(readFileSync(file, "utf8"))) offenders.push(rel);
    }
  }

  assert.deepEqual(offenders, []);
});
