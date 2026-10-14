<?php

namespace Tests\Unit\Support;

use App\Support\PublicLinks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every public link an admin can save goes through PublicLinks. These pin what is accepted (and how it is normalised)
 * and, with hostile input, everything that must never be: another scheme, another host dressed up as the platform,
 * credentials in the address, control characters, embed codes. The same vectors are checked on the site's own copy of
 * the rules (institutional/lib/public-links.ts, __tests__/public-links.test.mts): keep the two lists in step.
 */
class PublicLinksTest extends TestCase
{
    // --- social links -----------------------------------------------------------------------------------------------

    /** @return array<string, array{string, string, string}> platform, input, normalised */
    public static function acceptedSocial(): array
    {
        return [
            'facebook short domain (the live value)' => ['facebook', 'https://fb.com/provatfericenter', 'https://fb.com/provatfericenter'],
            'facebook www' => ['facebook', 'https://www.facebook.com/provatfericenter', 'https://www.facebook.com/provatfericenter'],
            'facebook mobile' => ['facebook', 'https://m.facebook.com/provatfericenter', 'https://m.facebook.com/provatfericenter'],
            'facebook profile.php id with query' => ['facebook', 'https://www.facebook.com/profile.php?id=100012345', 'https://www.facebook.com/profile.php?id=100012345'],
            'facebook upper-case host is normalised' => ['facebook', 'HTTPS://WWW.FACEBOOK.COM/Provatferi', 'https://www.facebook.com/Provatferi'],
            'facebook default port and fragment are dropped' => ['facebook', 'https://www.facebook.com:443/page#section', 'https://www.facebook.com/page'],
            'facebook surrounding whitespace' => ['facebook', "  https://fb.me/provatferi \n", 'https://fb.me/provatferi'],
            'linkedin company' => ['linkedin', 'https://www.linkedin.com/company/provatferi', 'https://www.linkedin.com/company/provatferi'],
            'linkedin country sub-domain' => ['linkedin', 'https://bd.linkedin.com/company/provatferi', 'https://bd.linkedin.com/company/provatferi'],
            'youtube handle' => ['youtube', 'https://www.youtube.com/@provatferi', 'https://www.youtube.com/@provatferi'],
            'youtube channel id' => ['youtube', 'https://www.youtube.com/channel/UCabcdefghijklmnopqrstuv', 'https://www.youtube.com/channel/UCabcdefghijklmnopqrstuv'],
            'youtube short domain' => ['youtube', 'https://youtu.be/dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXcQ'],
            'youtube playlist (query only)' => ['youtube', 'https://www.youtube.com/playlist?list=PL123', 'https://www.youtube.com/playlist?list=PL123'],
        ];
    }

    #[DataProvider('acceptedSocial')]
    public function test_a_genuine_platform_link_is_accepted_and_normalised(string $platform, string $input, string $expected): void
    {
        $this->assertSame(['url' => $expected, 'error' => null], PublicLinks::social($platform, $input));
    }

    /** @return array<string, array{string, string, string}> platform, input, error code */
    public static function rejectedSocial(): array
    {
        $rows = [
            // Not https
            'plain http' => ['facebook', 'http://www.facebook.com/page', 'scheme'],
            'javascript scheme' => ['facebook', 'javascript:alert(1)', 'scheme'],
            'javascript with a host-looking tail' => ['facebook', 'javascript://www.facebook.com/%0Aalert(1)', 'scheme'],
            'data scheme' => ['linkedin', 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==', 'scheme'],
            'vbscript scheme' => ['youtube', 'vbscript:msgbox(1)', 'scheme'],
            'file scheme' => ['facebook', 'file:///etc/passwd', 'scheme'],
            'ftp scheme' => ['facebook', 'ftp://facebook.com/page', 'scheme'],
            'scheme-relative' => ['facebook', '//www.facebook.com/page', 'scheme'],
            'no scheme at all' => ['facebook', 'facebook.com/page', 'scheme'],
            'mixed-case javascript' => ['facebook', 'JaVaScRiPt:alert(1)', 'scheme'],
            // Another host dressed up as the platform
            'platform name inside another domain' => ['facebook', 'https://facebook.com.evil.example/page', 'host'],
            'platform name as a prefix of another domain' => ['facebook', 'https://evilfacebook.com/page', 'host'],
            'platform name in the path of another host' => ['facebook', 'https://evil.example/www.facebook.com/page', 'host'],
            'platform name in the query of another host' => ['facebook', 'https://evil.example/?u=https://www.facebook.com/page', 'host'],
            'wrong platform' => ['facebook', 'https://www.linkedin.com/company/x', 'host'],
            'linkedin look-alike' => ['linkedin', 'https://linkedin.com.evil.example/company/x', 'host'],
            'youtube look-alike' => ['youtube', 'https://youtube.evil.example/@x', 'host'],
            'ip address host' => ['facebook', 'https://192.168.0.1/page', 'host'],
            // Credentials, ports, odd characters
            'user-info trick' => ['facebook', 'https://www.facebook.com@evil.example/page', 'invalid'],
            'user and password' => ['facebook', 'https://user:pass@www.facebook.com/page', 'invalid'],
            'other port' => ['facebook', 'https://www.facebook.com:8443/page', 'invalid'],
            'backslash host trick' => ['facebook', 'https://evil.example\\@www.facebook.com/page', 'invalid'],
            'space inside' => ['facebook', 'https://www.facebook.com/my page', 'invalid'],
            'tab inside' => ['facebook', "https://www.facebook.com/my\tpage", 'invalid'],
            'CRLF header injection' => ['facebook', "https://www.facebook.com/page\r\nSet-Cookie: x=1", 'invalid'],
            'LF only' => ['facebook', "https://www.facebook.com/page\nLocation: https://evil.example", 'invalid'],
            'NUL byte' => ['facebook', "https://www.facebook.com/pa\0ge", 'invalid'],
            'double quote (attribute break-out)' => ['facebook', 'https://www.facebook.com/page"onmouseover="alert(1)', 'invalid'],
            'single quote' => ['facebook', "https://www.facebook.com/page'onmouseover='alert(1)", 'invalid'],
            'angle brackets' => ['facebook', 'https://www.facebook.com/<script>alert(1)</script>', 'invalid'],
            'backtick' => ['facebook', 'https://www.facebook.com/`page`', 'invalid'],
            'braces' => ['facebook', 'https://www.facebook.com/{page}', 'invalid'],
            'non-ASCII (look-alike letter)' => ['facebook', "https://www.facebo\u{043E}k.com/page", 'invalid'],
            'bidi override character' => ['facebook', "https://www.facebook.com/\u{202E}page", 'invalid'],
            'punycode host' => ['facebook', 'https://xn--facebok-3ya.com/page', 'invalid'],
            'percent-encoded dot in host' => ['facebook', 'https://www%2Efacebook.com/page', 'invalid'],
            'empty' => ['facebook', '', 'invalid'],
            'only whitespace' => ['facebook', "   \n", 'invalid'],
            'https with no host' => ['facebook', 'https://', 'invalid'],
            // Home pages are not "our page"
            'home page only' => ['facebook', 'https://www.facebook.com/', 'path'],
            'bare domain' => ['linkedin', 'https://www.linkedin.com', 'path'],
            'youtube home' => ['youtube', 'https://www.youtube.com/', 'path'],
            'fragment only' => ['facebook', 'https://www.facebook.com/#page', 'path'],
        ];
        $rows['too long'] = ['facebook', 'https://www.facebook.com/'.str_repeat('a', 600), 'length'];

        return $rows;
    }

    #[DataProvider('rejectedSocial')]
    public function test_anything_that_is_not_a_genuine_platform_link_is_rejected_with_a_reason(string $platform, string $input, string $error): void
    {
        $result = PublicLinks::social($platform, $input);

        $this->assertNull($result['url'], 'a rejected link must not produce a URL');
        $this->assertSame($error, $result['error']);
    }

    public function test_an_unknown_platform_accepts_nothing(): void
    {
        $this->assertSame('host', PublicLinks::social('myspace', 'https://www.facebook.com/page')['error']);
    }

    // --- map links --------------------------------------------------------------------------------------------------

    /** @return array<string, array{string, string}> input, normalised */
    public static function acceptedMap(): array
    {
        return [
            'share short link' => ['https://maps.app.goo.gl/AbCdEf12345', 'https://maps.app.goo.gl/AbCdEf12345'],
            'place url' => ['https://www.google.com/maps/place/Chandina,+Bangladesh/@23.62,91.0,13z', 'https://www.google.com/maps/place/Chandina,+Bangladesh/@23.62,91.0,13z'],
            'query form' => ['https://www.google.com/maps?q=23.62,91.0', 'https://www.google.com/maps?q=23.62,91.0'],
            'search api form' => ['https://www.google.com/maps/search/?api=1&query=Chandina+Cumilla', 'https://www.google.com/maps/search/?api=1&query=Chandina+Cumilla'],
            'coordinates only' => ['https://www.google.com/maps/@23.6234,91.0123,15z', 'https://www.google.com/maps/@23.6234,91.0123,15z'],
            'maps.google.com with a query' => ['https://maps.google.com/?q=Chandina', 'https://maps.google.com/?q=Chandina'],
            'bangladesh domain' => ['https://www.google.com.bd/maps/place/Cumilla', 'https://www.google.com.bd/maps/place/Cumilla'],
            'legacy short link' => ['https://goo.gl/maps/AbCdEf123', 'https://goo.gl/maps/AbCdEf123'],
            'upper-case host is normalised' => ['HTTPS://WWW.GOOGLE.COM/maps/place/X', 'https://www.google.com/maps/place/X'],
            'fragment dropped' => ['https://www.google.com/maps/place/X#frag', 'https://www.google.com/maps/place/X'],
        ];
    }

    #[DataProvider('acceptedMap')]
    public function test_a_google_maps_link_is_accepted_and_normalised(string $input, string $expected): void
    {
        $this->assertSame(['url' => $expected, 'error' => null], PublicLinks::map($input));
    }

    /** @return array<string, array{string, string}> input, error code */
    public static function rejectedMap(): array
    {
        return [
            'embed iframe address' => ['https://www.google.com/maps/embed?pb=!1m18!1m12', 'embed'],
            'embed on maps.google.com' => ['https://maps.google.com/maps/embed?pb=x', 'embed'],
            'maps api' => ['https://www.google.com/maps/api/js?key=x', 'embed'],
            'iframe html pasted whole' => ['<iframe src="https://www.google.com/maps/embed?pb=x"></iframe>', 'embed'],
            'script tag' => ['<script>alert(1)</script>', 'embed'],
            'http not https' => ['http://www.google.com/maps/place/X', 'scheme'],
            'javascript scheme' => ['javascript:alert(document.domain)', 'scheme'],
            // Carries a <script>, so it is reported as markup (embed) rather than as a wrong scheme; either way it is refused.
            'data scheme carrying markup' => ['data:text/html,<script>alert(1)</script>', 'embed'],
            'data scheme without markup' => ['data:text/plain;base64,YWxlcnQoMSk=', 'scheme'],
            'google but not maps' => ['https://www.google.com/search?q=chandina', 'path'],
            'google search home' => ['https://www.google.com/', 'path'],
            'maps path look-alike' => ['https://www.google.com/mapsfoo', 'path'],
            'short link without an id' => ['https://maps.app.goo.gl/', 'path'],
            'short link with a path' => ['https://maps.app.goo.gl/a/b/c', 'path'],
            'goo.gl that is not maps' => ['https://goo.gl/AbCdEf', 'path'],
            'maps.google.com home' => ['https://maps.google.com/', 'path'],
            'another host' => ['https://evil.example/maps/place/X', 'host'],
            'look-alike host' => ['https://www.google.com.evil.example/maps/place/X', 'host'],
            'user-info trick' => ['https://www.google.com@evil.example/maps/place/X', 'invalid'],
            'other port' => ['https://www.google.com:8443/maps/place/X', 'invalid'],
            'CRLF' => ["https://www.google.com/maps/place/X\r\nX-Evil: 1", 'invalid'],
            'space' => ['https://www.google.com/maps/place/Chandina Cumilla', 'invalid'],
            'quote break-out' => ['https://www.google.com/maps/place/X"onload="alert(1)', 'invalid'],
            'too long' => ['https://www.google.com/maps/place/'.str_repeat('a', 1100), 'length'],
            'empty' => ['', 'invalid'],
        ];
    }

    #[DataProvider('rejectedMap')]
    public function test_anything_that_is_not_a_google_maps_link_is_rejected_with_a_reason(string $input, string $error): void
    {
        $result = PublicLinks::map($input);

        $this->assertNull($result['url']);
        $this->assertSame($error, $result['error']);
    }

    // --- phone numbers ----------------------------------------------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function acceptedPhones(): array
    {
        return [
            'the live number' => ['+8801625050408', '+8801625050408'],
            'with spaces' => ['+880 1625 050408', '+880 1625 050408'],
            'with brackets and hyphens' => ['(02) 5555-1234', '(02) 5555-1234'],
            'local number' => ['01625050408', '01625050408'],
            'empty is allowed (optional)' => ['', ''],
            'surrounding space is trimmed, runs of spaces collapse' => ["  +880   1625050408 \n", '+880 1625050408'],
        ];
    }

    #[DataProvider('acceptedPhones')]
    public function test_a_phone_number_is_accepted_as_typed(string $input, string $expected): void
    {
        $this->assertSame(['value' => $expected, 'error' => null], PublicLinks::phone($input));
    }

    /** @return array<string, array{string}> */
    public static function rejectedPhones(): array
    {
        return [
            'letters' => ['call us'],
            'a script' => ['<script>alert(1)</script>'],
            'tel scheme' => ['tel:+8801625050408'],
            'javascript' => ['javascript:alert(1)'],
            'two numbers' => ['+8801625050408, +8801700000000'],
            'too few digits' => ['12345'],
            'too many digits' => ['+88016250504081625050408'],
            'extension letter' => ['+8801625050408 ext 5'],
            'line break' => ["+8801625050408\r\nBcc: x@y.z"],
            'plus in the middle' => ['016+25050408'],
        ];
    }

    #[DataProvider('rejectedPhones')]
    public function test_anything_that_is_not_one_phone_number_is_rejected(string $input): void
    {
        $this->assertSame('format', PublicLinks::phone($input)['error']);
    }

    // --- plain text -------------------------------------------------------------------------------------------------

    public function test_plain_text_may_span_lines_but_never_carry_markup_or_control_characters(): void
    {
        $this->assertTrue(PublicLinks::isPlainText("লেবাশ, ডাকঘর দোল্লাই\nচান্দিনা, কুমিল্লা"));
        $this->assertTrue(PublicLinks::isPlainText("Lebash, Dollai Nowabpur\r\nChandina"));
        $this->assertFalse(PublicLinks::isPlainText('<b>Lebash</b>'));
        $this->assertFalse(PublicLinks::isPlainText('a > b'));
        $this->assertFalse(PublicLinks::isPlainText("a\0b"));
        $this->assertFalse(PublicLinks::isPlainText("a\x07b"));
    }
}
