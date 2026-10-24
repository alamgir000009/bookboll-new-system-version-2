<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * What a user agent string says about the thing holding a session.
 *
 * Deliberately dependency-free: the admin session list only needs enough to let
 * a human recognise their own device in a list and spot one that isn't theirs
 * ("Chrome on Windows", "iPhone · iOS 17"), which is a much smaller problem
 * than full UA classification. Order of the checks is the whole algorithm —
 * every family below is claimed by a broader one further down the list, so the
 * most specific match has to be tested first.
 */
final class UserAgentDetails
{
    public const DEVICE_MOBILE = 'mobile';

    public const DEVICE_TABLET = 'tablet';

    public const DEVICE_DESKTOP = 'desktop';

    public const DEVICE_BOT = 'bot';

    public const DEVICE_UNKNOWN = 'unknown';

    /**
     * Clients that only ever ship inside a phone or tablet app. Their agents
     * carry no phone-vs-tablet token at all, so they are called mobile — the
     * common case — rather than guessed at from the platform alone.
     *
     * @var string[]
     */
    private const MOBILE_APP_CLIENTS = ['Dart', 'Flutter', 'OkHttp', 'Alamofire', 'CFNetwork', 'Expo', 'React Native'];

    private function __construct(
        public readonly ?string $raw,
        public readonly ?string $browser,
        public readonly ?string $browserVersion,
        public readonly ?string $platform,
        public readonly ?string $platformVersion,
        public readonly string $deviceType,
        public readonly ?string $deviceModel,
    ) {}

    public static function parse(?string $userAgent): self
    {
        $raw = is_string($userAgent) ? trim($userAgent) : null;

        if ($raw === null || $raw === '') {
            return new self(null, null, null, null, null, self::DEVICE_UNKNOWN, null);
        }

        [$browser, $browserVersion] = self::detectBrowser($raw);
        [$platform, $platformVersion] = self::detectPlatform($raw);
        $deviceModel = self::detectDeviceModel($raw);

        return new self(
            raw: $raw,
            browser: $browser,
            browserVersion: $browserVersion,
            platform: $platform,
            platformVersion: $platformVersion,
            deviceType: self::detectDeviceType($raw, $platform, $browser),
            deviceModel: $deviceModel,
        );
    }

    /**
     * "Chrome 131 on macOS 15" — the column a human actually reads.
     */
    public function summary(): string
    {
        $client = $this->clientLabel();
        $platform = $this->platformLabel();

        if ($client !== null && $platform !== null) {
            return "{$client} on {$platform}";
        }

        return $client ?? $platform ?? 'Unknown device';
    }

    /** "Chrome 131", or null when the agent named no recognisable client. */
    public function clientLabel(): ?string
    {
        if ($this->browser === null) {
            return null;
        }

        return $this->browserVersion !== null
            ? "{$this->browser} {$this->browserVersion}"
            : $this->browser;
    }

    /** "iOS 17.4", or the device model when that says more ("iPhone"). */
    public function platformLabel(): ?string
    {
        if ($this->platform === null) {
            return $this->deviceModel;
        }

        $label = $this->platformVersion !== null
            ? "{$this->platform} {$this->platformVersion}"
            : $this->platform;

        if ($this->deviceModel !== null && ! Str::contains($label, $this->deviceModel)) {
            return "{$this->deviceModel} · {$label}";
        }

        return $label;
    }

    public function isBot(): bool
    {
        return $this->deviceType === self::DEVICE_BOT;
    }

    public function isMobile(): bool
    {
        return in_array($this->deviceType, [self::DEVICE_MOBILE, self::DEVICE_TABLET], true);
    }

    public function isUnknown(): bool
    {
        return $this->deviceType === self::DEVICE_UNKNOWN;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function detectBrowser(string $ua): array
    {
        // Native mobile and server-side HTTP clients come first: these are the
        // app's own traffic, and several of them embed a browser token that
        // would otherwise misreport them as Safari or Chrome.
        $clients = [
            'Dart' => '/\bDart\/([\d.]+)/i',
            'Flutter' => '/\bFlutter\b/i',
            'OkHttp' => '/\bokhttp\/([\d.]+)/i',
            'Alamofire' => '/\bAlamofire\/([\d.]+)/i',
            'CFNetwork' => '/\bCFNetwork\/([\d.]+)/i',
            'Expo' => '/\bExpo\/([\d.]+)/i',
            'React Native' => '/\breact-native\b/i',
            'Axios' => '/\baxios\/([\d.]+)/i',
            'Guzzle' => '/\bGuzzleHttp\/([\d.]+)/i',
            'Postman' => '/\bPostmanRuntime\/([\d.]+)/i',
            'Insomnia' => '/\binsomnia\/([\d.]+)/i',
            'curl' => '/\bcurl\/([\d.]+)/i',
            'Wget' => '/\bWget\/([\d.]+)/i',
            'Python Requests' => '/\bpython-requests\/([\d.]+)/i',
        ];

        foreach ($clients as $name => $pattern) {
            if (preg_match($pattern, $ua, $matches)) {
                return [$name, self::majorVersion($matches[1] ?? null)];
            }
        }

        // Browsers, narrowest first. Every Chromium browser also says "Chrome",
        // and Chrome itself also says "Safari", so the list only ever reads
        // top-to-bottom.
        $browsers = [
            'Edge' => '/\bEdg(?:e|A|iOS)?\/([\d.]+)/i',
            'Opera' => '/\b(?:OPR|OPiOS|Opera)\/([\d.]+)/i',
            'Samsung Internet' => '/\bSamsungBrowser\/([\d.]+)/i',
            'Vivaldi' => '/\bVivaldi\/([\d.]+)/i',
            'Brave' => '/\bBrave\/([\d.]+)/i',
            'Yandex' => '/\bYaBrowser\/([\d.]+)/i',
            'UC Browser' => '/\bUCBrowser\/([\d.]+)/i',
            'Firefox' => '/\b(?:Firefox|FxiOS)\/([\d.]+)/i',
            'Internet Explorer' => '/\b(?:MSIE\s([\d.]+)|Trident\/.*\brv:([\d.]+))/i',
            'Chrome' => '/\b(?:Chrome|CriOS)\/([\d.]+)/i',
            'Safari' => '/\bVersion\/([\d.]+).*\bSafari\b/i',
        ];

        foreach ($browsers as $name => $pattern) {
            if (preg_match($pattern, $ua, $matches)) {
                $version = $matches[1] ?? null;

                // Internet Explorer reports its version in one of two groups.
                if (($version === null || $version === '') && isset($matches[2])) {
                    $version = $matches[2];
                }

                return [$name, self::majorVersion($version)];
            }
        }

        // A bare "…Safari/605.1.15" with no Version/ token: an in-app WebView.
        if (preg_match('/\bSafari\b/i', $ua)) {
            return ['Safari', null];
        }

        return [null, null];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function detectPlatform(string $ua): array
    {
        // iPadOS before iOS (an iPad reports "CPU OS", an iPhone "CPU iPhone
        // OS"), and Android before Linux, which it contains.
        if (preg_match('/\biPad\b/i', $ua)) {
            return ['iPadOS', self::versionFrom($ua, '/\bCPU (?:iPhone )?OS ([\d_]+)/i')];
        }

        if (preg_match('/\b(?:iPhone|iPod)\b/i', $ua)) {
            return ['iOS', self::versionFrom($ua, '/\bCPU (?:iPhone )?OS ([\d_]+)/i')];
        }

        if (preg_match('/\bAndroid\b/i', $ua)) {
            return ['Android', self::versionFrom($ua, '/\bAndroid ([\d.]+)/i')];
        }

        if (preg_match('/\bWindows NT ([\d.]+)/i', $ua, $matches)) {
            return ['Windows', self::windowsRelease($matches[1])];
        }

        if (preg_match('/\b(?:Mac OS X|macOS)\b/i', $ua)) {
            return ['macOS', self::macRelease(self::versionFrom($ua, '/\bMac OS X ([\d_.]+)/i'))];
        }

        if (preg_match('/\bCrOS\b/i', $ua)) {
            return ['ChromeOS', null];
        }

        if (preg_match('/\b(?:Ubuntu|Fedora|Debian|Linux)\b/i', $ua)) {
            return ['Linux', null];
        }

        if (preg_match('/\bDarwin\/([\d.]+)/i', $ua, $matches)) {
            // CFNetwork/Darwin with no device token — an Apple platform, but
            // the agent does not say which.
            return ['Apple', self::majorVersion($matches[1])];
        }

        return [null, null];
    }

    private static function detectDeviceModel(string $ua): ?string
    {
        foreach (['iPhone', 'iPad', 'iPod', 'Macintosh'] as $apple) {
            if (preg_match('/\b'.$apple.'\b/i', $ua)) {
                return $apple === 'Macintosh' ? 'Mac' : $apple;
            }
        }

        // Android puts the marketing model between the build token and the
        // closing paren: "Android 14; Pixel 8 Pro) AppleWebKit…".
        if (preg_match('/\bAndroid[^;)]*;\s*([^;)]+?)(?:\s+Build\/[^;)]*)?\)/i', $ua, $matches)) {
            $model = trim($matches[1]);

            // "wv" marks an Android WebView, and locale-only segments ("en-gb")
            // appear in place of a model on older agents.
            if ($model !== '' && ! preg_match('/^(?:wv|[a-z]{2}(?:-[a-z]{2})?)$/i', $model)) {
                return Str::limit($model, 40, '');
            }
        }

        return null;
    }

    private static function detectDeviceType(string $ua, ?string $platform, ?string $browser): string
    {
        if (preg_match('/\b(?:bot|crawler|spider|crawl|slurp|facebookexternalhit|monitor|uptime|pingdom|headless)\b/i', $ua)) {
            return self::DEVICE_BOT;
        }

        // An explicit tablet token outranks everything below, including the
        // native-app rule — an iPad running the Flutter app is still a tablet.
        if (in_array($platform, ['iPadOS'], true) || preg_match('/\b(?:iPad|Tablet|PlayBook|Silk)\b/i', $ua)) {
            return self::DEVICE_TABLET;
        }

        if (in_array($browser, self::MOBILE_APP_CLIENTS, true)) {
            return self::DEVICE_MOBILE;
        }

        // An Android tablet is "Android" without "Mobile"; a phone has both.
        if ($platform === 'Android') {
            return preg_match('/\bMobile\b/i', $ua) ? self::DEVICE_MOBILE : self::DEVICE_TABLET;
        }

        if (in_array($platform, ['iOS'], true) || preg_match('/\b(?:Mobile|iPhone|iPod|Windows Phone)\b/i', $ua)) {
            return self::DEVICE_MOBILE;
        }

        if (in_array($platform, ['Windows', 'macOS', 'Linux', 'ChromeOS'], true)) {
            return self::DEVICE_DESKTOP;
        }

        return self::DEVICE_UNKNOWN;
    }

    /** Marketing name for an NT kernel version — "10" reads better than "10.0". */
    private static function windowsRelease(string $ntVersion): ?string
    {
        return match ($ntVersion) {
            '10.0' => '10/11',
            '6.3' => '8.1',
            '6.2' => '8',
            '6.1' => '7',
            '6.0' => 'Vista',
            '5.1', '5.2' => 'XP',
            default => $ntVersion,
        };
    }

    /**
     * Safari froze its reported Mac version at 10.15, so anything at or above
     * that is reported without a number rather than with a wrong one.
     */
    private static function macRelease(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        return version_compare($version, '10.15', '>=') ? null : $version;
    }

    private static function versionFrom(string $ua, string $pattern): ?string
    {
        if (! preg_match($pattern, $ua, $matches)) {
            return null;
        }

        // iOS and macOS separate version parts with underscores.
        $version = str_replace('_', '.', $matches[1]);

        // Two parts is the most that carries meaning here (17.4, not 17.4.1).
        $parts = array_slice(explode('.', $version), 0, 2);

        return implode('.', $parts);
    }

    private static function majorVersion(?string $version): ?string
    {
        if ($version === null || $version === '') {
            return null;
        }

        return explode('.', $version)[0];
    }
}
