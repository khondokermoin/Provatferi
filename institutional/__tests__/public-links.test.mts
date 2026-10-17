import assert from "node:assert/strict";
import test from "node:test";
import { cleanText, safeMapUrl, safePhone, safeSocialUrl, safeWebsiteUrl, telHref, usablePublicEmail } from "../lib/public-links.ts";

/**
 * The site's own copy of the link/phone/e-mail rules (admin-erp: tests/Unit/Support/PublicLinksTest.php holds the same
 * vectors for the original). The site re-checks everything the ERP's public API returns before it becomes an href or a
 * JSON-LD value, so a bad row on the other side can never become a javascript: link or an admin@ address on a page.
 * Keep the two vector lists in step.
 */

const accepted: Array<[string, string, string]> = [
  ["facebook", "https://fb.com/provatfericenter", "https://fb.com/provatfericenter"],
  ["facebook", "https://www.facebook.com/provatfericenter", "https://www.facebook.com/provatfericenter"],
  ["facebook", "https://m.facebook.com/provatfericenter", "https://m.facebook.com/provatfericenter"],
  ["facebook", "https://www.facebook.com/profile.php?id=100012345", "https://www.facebook.com/profile.php?id=100012345"],
  ["facebook", "HTTPS://WWW.FACEBOOK.COM/Provatferi", "https://www.facebook.com/Provatferi"],
  ["facebook", "https://www.facebook.com:443/page#section", "https://www.facebook.com/page"],
  ["facebook", "  https://fb.me/provatferi \n", "https://fb.me/provatferi"],
  ["linkedin", "https://www.linkedin.com/company/provatferi", "https://www.linkedin.com/company/provatferi"],
  ["linkedin", "https://bd.linkedin.com/company/provatferi", "https://bd.linkedin.com/company/provatferi"],
  ["youtube", "https://www.youtube.com/@provatferi", "https://www.youtube.com/@provatferi"],
  ["youtube", "https://youtu.be/dQw4w9WgXcQ", "https://youtu.be/dQw4w9WgXcQ"],
  ["youtube", "https://www.youtube.com/playlist?list=PL123", "https://www.youtube.com/playlist?list=PL123"],
];

test("a genuine platform link is accepted and normalised", () => {
  for (const [platform, input, expected] of accepted) {
    assert.equal(safeSocialUrl(platform, input), expected, `${platform}: ${JSON.stringify(input)}`);
  }
});

const rejectedSocial: Array<[string, string, string]> = [
  ["plain http", "facebook", "http://www.facebook.com/page"],
  ["javascript scheme", "facebook", "javascript:alert(1)"],
  ["javascript with a host-looking tail", "facebook", "javascript://www.facebook.com/%0Aalert(1)"],
  ["data scheme", "linkedin", "data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=="],
  ["vbscript scheme", "youtube", "vbscript:msgbox(1)"],
  ["file scheme", "facebook", "file:///etc/passwd"],
  ["ftp scheme", "facebook", "ftp://facebook.com/page"],
  ["scheme-relative", "facebook", "//www.facebook.com/page"],
  ["no scheme at all", "facebook", "facebook.com/page"],
  ["mixed-case javascript", "facebook", "JaVaScRiPt:alert(1)"],
  ["platform name inside another domain", "facebook", "https://facebook.com.evil.example/page"],
  ["platform name as a prefix of another domain", "facebook", "https://evilfacebook.com/page"],
  ["platform name in the path of another host", "facebook", "https://evil.example/www.facebook.com/page"],
  ["platform name in the query of another host", "facebook", "https://evil.example/?u=https://www.facebook.com/page"],
  ["wrong platform", "facebook", "https://www.linkedin.com/company/x"],
  ["linkedin look-alike", "linkedin", "https://linkedin.com.evil.example/company/x"],
  ["youtube look-alike", "youtube", "https://youtube.evil.example/@x"],
  ["ip address host", "facebook", "https://192.168.0.1/page"],
  ["user-info trick", "facebook", "https://www.facebook.com@evil.example/page"],
  ["user and password", "facebook", "https://user:pass@www.facebook.com/page"],
  ["other port", "facebook", "https://www.facebook.com:8443/page"],
  ["backslash host trick", "facebook", "https://evil.example\\@www.facebook.com/page"],
  ["space inside", "facebook", "https://www.facebook.com/my page"],
  ["tab inside", "facebook", "https://www.facebook.com/my\tpage"],
  ["CRLF header injection", "facebook", "https://www.facebook.com/page\r\nSet-Cookie: x=1"],
  ["LF only", "facebook", "https://www.facebook.com/page\nLocation: https://evil.example"],
  ["NUL byte", "facebook", "https://www.facebook.com/pa\0ge"],
  ["double quote (attribute break-out)", "facebook", 'https://www.facebook.com/page"onmouseover="alert(1)'],
  ["single quote", "facebook", "https://www.facebook.com/page'onmouseover='alert(1)"],
  ["angle brackets", "facebook", "https://www.facebook.com/<script>alert(1)</script>"],
  ["backtick", "facebook", "https://www.facebook.com/`page`"],
  ["braces", "facebook", "https://www.facebook.com/{page}"],
  ["non-ASCII look-alike letter", "facebook", "https://www.faceboоk.com/page"],
  ["bidi override character", "facebook", "https://www.facebook.com/‮page"],
  ["punycode host", "facebook", "https://xn--facebok-3ya.com/page"],
  ["percent-encoded dot in host", "facebook", "https://www%2Efacebook.com/page"],
  ["empty", "facebook", ""],
  ["only whitespace", "facebook", "   \n"],
  ["https with no host", "facebook", "https://"],
  ["home page only", "facebook", "https://www.facebook.com/"],
  ["bare domain", "linkedin", "https://www.linkedin.com"],
  ["youtube home", "youtube", "https://www.youtube.com/"],
  ["fragment only", "facebook", "https://www.facebook.com/#page"],
  ["too long", "facebook", `https://www.facebook.com/${"a".repeat(600)}`],
  ["unknown platform", "myspace", "https://www.facebook.com/page"],
];

test("anything that is not a genuine platform link is rejected", () => {
  for (const [name, platform, input] of rejectedSocial) {
    assert.equal(safeSocialUrl(platform, input), null, `${name}: ${JSON.stringify(input)}`);
  }
});

test("a value that is not even a string is rejected", () => {
  for (const value of [null, undefined, 42, {}, [], true]) {
    assert.equal(safeSocialUrl("facebook", value), null);
    assert.equal(safeMapUrl(value), null);
    assert.equal(safePhone(value), null);
    assert.equal(usablePublicEmail(value), null);
  }
});

const acceptedMap: Array<[string, string]> = [
  ["https://maps.app.goo.gl/AbCdEf12345", "https://maps.app.goo.gl/AbCdEf12345"],
  ["https://www.google.com/maps/place/Chandina,+Bangladesh/@23.62,91.0,13z", "https://www.google.com/maps/place/Chandina,+Bangladesh/@23.62,91.0,13z"],
  ["https://www.google.com/maps?q=23.62,91.0", "https://www.google.com/maps?q=23.62,91.0"],
  ["https://www.google.com/maps/search/?api=1&query=Chandina+Cumilla", "https://www.google.com/maps/search/?api=1&query=Chandina+Cumilla"],
  ["https://www.google.com/maps/@23.6234,91.0123,15z", "https://www.google.com/maps/@23.6234,91.0123,15z"],
  ["https://maps.google.com/?q=Chandina", "https://maps.google.com/?q=Chandina"],
  ["https://www.google.com.bd/maps/place/Cumilla", "https://www.google.com.bd/maps/place/Cumilla"],
  ["https://goo.gl/maps/AbCdEf123", "https://goo.gl/maps/AbCdEf123"],
  ["HTTPS://WWW.GOOGLE.COM/maps/place/X", "https://www.google.com/maps/place/X"],
  ["https://www.google.com/maps/place/X#frag", "https://www.google.com/maps/place/X"],
];

test("a Google Maps link is accepted and normalised", () => {
  for (const [input, expected] of acceptedMap) assert.equal(safeMapUrl(input), expected, input);
});

const rejectedMap: Array<[string, string]> = [
  ["embed iframe address", "https://www.google.com/maps/embed?pb=!1m18!1m12"],
  ["embed on maps.google.com", "https://maps.google.com/maps/embed?pb=x"],
  ["maps api", "https://www.google.com/maps/api/js?key=x"],
  ["iframe html pasted whole", '<iframe src="https://www.google.com/maps/embed?pb=x"></iframe>'],
  ["script tag", "<script>alert(1)</script>"],
  ["http not https", "http://www.google.com/maps/place/X"],
  ["javascript scheme", "javascript:alert(document.domain)"],
  ["data scheme", "data:text/html,<script>alert(1)</script>"],
  ["google but not maps", "https://www.google.com/search?q=chandina"],
  ["google home", "https://www.google.com/"],
  ["maps path look-alike", "https://www.google.com/mapsfoo"],
  ["short link without an id", "https://maps.app.goo.gl/"],
  ["short link with a path", "https://maps.app.goo.gl/a/b/c"],
  ["goo.gl that is not maps", "https://goo.gl/AbCdEf"],
  ["maps.google.com home", "https://maps.google.com/"],
  ["another host", "https://evil.example/maps/place/X"],
  ["look-alike host", "https://www.google.com.evil.example/maps/place/X"],
  ["user-info trick", "https://www.google.com@evil.example/maps/place/X"],
  ["other port", "https://www.google.com:8443/maps/place/X"],
  ["CRLF", "https://www.google.com/maps/place/X\r\nX-Evil: 1"],
  ["space", "https://www.google.com/maps/place/Chandina Cumilla"],
  ["quote break-out", 'https://www.google.com/maps/place/X"onload="alert(1)'],
  ["too long", `https://www.google.com/maps/place/${"a".repeat(1100)}`],
  ["empty", ""],
];

test("anything that is not a Google Maps link is rejected", () => {
  for (const [name, input] of rejectedMap) assert.equal(safeMapUrl(input), null, `${name}: ${JSON.stringify(input)}`);
});

test("a phone number is accepted as typed and the tel: address is digits only", () => {
  assert.equal(safePhone("+8801625050408"), "+8801625050408");
  assert.equal(safePhone("(02) 5555-1234"), "(02) 5555-1234");
  assert.equal(safePhone("  +880   1625050408 \n"), "+880 1625050408");
  assert.equal(telHref("+880 1625-050408"), "tel:+8801625050408");
  assert.equal(telHref("(02) 5555-1234"), "tel:0255551234");
});

test("anything that is not one phone number is rejected", () => {
  for (const bad of ["call us", "<script>alert(1)</script>", "tel:+8801625050408", "javascript:alert(1)", "+8801625050408, +8801700000000", "12345", "+88016250504081625050408", "+8801625050408 ext 5", "+8801625050408\r\nBcc: x@y.z", "016+25050408", ""]) {
    assert.equal(safePhone(bad), null, JSON.stringify(bad));
  }
});

test("only an address that may be published is accepted: never admin@, no-reply@ or security@", () => {
  assert.equal(usablePublicEmail("info@provatferi.org"), "info@provatferi.org");
  assert.equal(usablePublicEmail("  Support@Provatferi.ORG "), "support@provatferi.org");
  assert.equal(usablePublicEmail("office@example.org"), "office@example.org");
  for (const bad of ["admin@provatferi.org", "ADMIN@Provatferi.org", "admin@example.org", "no-reply@provatferi.org", "noreply@example.org", "do-not-reply@example.org", "security@provatferi.org", "not-an-email", "a@b", "info@provatferi.org\r\nBcc: x@y.z", "<info@provatferi.org>", "info@provatferi.org, support@provatferi.org", "boss%0d%0abcc@example.org", "a%40b@example.org", ""]) {
    assert.equal(usablePublicEmail(bad), null, JSON.stringify(bad));
  }
});

const acceptedWebsites: Array<[string, string]> = [
  ["https://example.org", "https://example.org"],
  ["https://www.example.org/about/me?lang=bn", "https://www.example.org/about/me?lang=bn"],
  ["HTTPS://WWW.Example.ORG/Page", "https://www.example.org/Page"],
  ["https://example.org:443/a#top", "https://example.org/a"],
  ["https://roney.com.bd/", "https://roney.com.bd/"],
  ["https://blog.example.net/posts/1", "https://blog.example.net/posts/1"],
  ["  https://example.org/x \n", "https://example.org/x"],
];

test("a person's public website is accepted and normalised", () => {
  for (const [input, expected] of acceptedWebsites) assert.equal(safeWebsiteUrl(input), expected, input);
});

test("anything that cannot be somebody's public website is rejected", () => {
  const rejected = [
    "", "http://example.org", "javascript:alert(1)", "data:text/html;base64,PHNjcmlwdD4=", "example.org", "//example.org", "ftp://example.org/file",
    "https://user:pass@example.org/", "https://example.org@evil.example/", "https://example.org:8443/", "https://example.org/a b", 'https://example.org/"x',
    "https://example.org/<script>", "https://example.org\\evil", "https://exämple.org/", "https://xn--exmple-cua.org/", "https://example.org/a\nSet-Cookie: x=1",
    `https://example.org/${"a".repeat(600)}`, "https://192.168.1.10/admin", "https://127.0.0.1/", "https://nas.local/share", "https://localhost/", "https://intranet/",
    "https://site.test/",
  ];
  for (const bad of rejected) assert.equal(safeWebsiteUrl(bad), null, JSON.stringify(bad.slice(0, 60)));
  assert.equal(safeWebsiteUrl(null), null);
  assert.equal(safeWebsiteUrl(42), null);
});

test("cleanText keeps line breaks and Bengali, turns other control characters into spaces, and caps the length", () => {
  assert.equal(cleanText("  লেবাশ, দোল্লাই\nচান্দিনা  "), "লেবাশ, দোল্লাই\nচান্দিনা");
  assert.equal(cleanText("a\u0000b\u0007c d"), "a b c d");
  assert.equal(cleanText("   "), null);
  assert.equal(cleanText(null), null);
  assert.equal(cleanText("x".repeat(50), 10), "x".repeat(10));
});
