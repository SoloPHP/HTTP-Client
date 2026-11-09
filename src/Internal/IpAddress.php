<?php

declare(strict_types=1);

namespace Solo\HttpClient\Internal;

/**
 * Reads IP addresses in hosts and tells public addresses from the rest, for withPublicHostsOnly().
 *
 * Public means globally reachable unicast. The ranges below follow the IANA special-purpose address registries,
 * and an IPv6 address must also lie in 2000::/3, the only block allocated for global unicast. An IPv4-mapped
 * address (::ffff:0:0/96) is the IPv4 address it carries; a NAT64 address (64:ff9b::/96) is public when the IPv4
 * address it embeds is.
 *
 * @internal
 */
final class IpAddress
{
    /** IPv4 ranges that are not public */
    private const array IPV4_RANGES = [
        '0.0.0.0/8',        // "this network"; 0.0.0.0 reaches the local host
        '10.0.0.0/8',       // private
        '100.64.0.0/10',    // shared address space of carrier-grade NAT
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local, including the cloud metadata service at 169.254.169.254
        '172.16.0.0/12',    // private
        '192.0.0.0/24',     // IETF protocol assignments
        '192.0.2.0/24',     // documentation
        '192.88.99.0/24',   // former 6to4 relay anycast
        '192.168.0.0/16',   // private
        '198.18.0.0/15',    // benchmarking
        '198.51.100.0/24',  // documentation
        '203.0.113.0/24',   // documentation
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved, including the broadcast address 255.255.255.255
    ];

    /** Ranges inside 2000::/3 that are not public */
    private const array IPV6_RANGES = [
        '2001::/23',        // IETF protocol assignments, including Teredo
        '2001:db8::/32',    // documentation
        '2002::/16',        // 6to4, which reaches the IPv4 address it embeds
        '3fff::/20',        // documentation
    ];

    private const string IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xFF\xFF";

    /**
     * The address a host spells out, in canonical form: an IPv6 address in brackets, a dotted quad, or another
     * numeric IPv4 spelling that cURL reads as an address, such as 2130706433, 0x7f.1, 0177.0.0.1 or 127.1.
     * Returns null for a host name.
     */
    public static function fromHost(string $host): ?string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return self::normalize(substr($host, 1, -1));
        }

        return self::normalize($host) ?? self::numericIpv4(rtrim($host, '.'));
    }

    /**
     * The canonical form of an IP address, or null when the string is not one. An IPv4-mapped IPv6 address
     * becomes the IPv4 address it carries.
     */
    public static function normalize(string $address): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $binary = (string) inet_pton($address);
        if (str_starts_with($binary, self::IPV4_MAPPED_PREFIX)) {
            $binary = substr($binary, 12);
        }

        return (string) inet_ntop($binary);
    }

    /**
     * Whether an address in canonical form is globally reachable unicast.
     */
    public static function isPublic(string $address): bool
    {
        $binary = (string) inet_pton($address);

        if (strlen($binary) === 4) {
            return !self::inAnyRange($binary, self::IPV4_RANGES);
        }

        if (self::inRange($binary, '64:ff9b::/96')) {
            return self::isPublic((string) inet_ntop(substr($binary, 12)));
        }

        return self::inRange($binary, '2000::/3') && !self::inAnyRange($binary, self::IPV6_RANGES);
    }

    /**
     * @param list<string> $ranges
     */
    private static function inAnyRange(string $binary, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inRange($binary, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $binary, string $range): bool
    {
        $slash = (int) strpos($range, '/');
        $network = (string) inet_pton(substr($range, 0, $slash));
        $bits = (int) substr($range, $slash + 1);

        if (strlen($binary) !== strlen($network)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $mask = (0xFF << (8 - $bits % 8)) & 0xFF;

        return strncmp($binary, $network, $bytes) === 0
            && ($bytes === strlen($binary) || (ord($binary[$bytes]) & $mask) === (ord($network[$bytes]) & $mask));
    }

    /**
     * cURL reads a host of one to four numeric parts as an IPv4 address, as inet_aton() does: each part is decimal,
     * 0-prefixed octal or 0x-prefixed hexadecimal, and the last part fills the bytes the others leave.
     */
    private static function numericIpv4(string $host): ?string
    {
        $parts = explode('.', $host);
        if (count($parts) > 4) {
            return null;
        }

        // Floats hold every accepted value exactly, on 32-bit builds too.
        $address = 0.0;
        foreach ($parts as $i => $part) {
            if (preg_match('/^(?:0[xX][0-9a-fA-F]+|0[0-7]*|[1-9][0-9]*)$/D', $part) !== 1) {
                return null;
            }

            $value = match (true) {
                strncasecmp($part, '0x', 2) === 0 => (float) hexdec(substr($part, 2)),
                $part[0] === '0' => (float) octdec($part),
                default => (float) $part,
            };
            $size = 256.0 ** ($i === count($parts) - 1 ? 5 - count($parts) : 1);
            if ($value >= $size) {
                return null;
            }

            $address = $address * $size + $value;
        }

        $octets = [];
        for ($shift = 3; $shift >= 0; --$shift) {
            $octets[] = (int) fmod(floor($address / 256.0 ** $shift), 256.0);
        }

        return implode('.', $octets);
    }
}
