<?php

namespace App\Support;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class PublicHttpUrlGuard
{
    public static function assertPublic(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        $port = $parts['port'] ?? null;

        if (
            $parts === false
            || ! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || ($port !== null && ! in_array((int) $port, [80, 443], true))
        ) {
            throw new RuntimeException('La URL debe usar HTTP o HTTPS y apuntar a un servidor público.');
        }

        $host = rtrim(strtolower($host), '.');
        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_values(array_filter(array_map(
                static fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
                @dns_get_record($host, DNS_A | DNS_AAAA) ?: [],
            )));

        if ($addresses === []) {
            throw new RuntimeException('No se pudo validar el servidor de la URL proporcionada.');
        }

        foreach ($addresses as $address) {
            if (! self::isPublicIp($address)) {
                throw new RuntimeException('La URL debe apuntar a un servidor público.');
            }
        }

        return $addresses[0];
    }

    public static function get(string $url, array $headers, int $timeout): Response
    {
        for ($redirects = 0; $redirects <= 5; $redirects++) {
            $response = Http::withOptions(self::requestOptions($url))
                ->withHeaders($headers)
                ->timeout($timeout)
                ->get($url);

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return $response;
            }

            $location = $response->header('Location');
            if (! $location || $redirects === 5) {
                break;
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
        }

        throw new RuntimeException('La URL redirige demasiadas veces o no indica un destino válido.');
    }

    public static function requestOptions(string $url): array
    {
        $address = self::assertPublic($url);
        $parts = parse_url($url);
        $host = trim((string) $parts['host'], '[]');
        $scheme = strtolower((string) $parts['scheme']);
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $resolveHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host;
        $resolveAddress = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$address}]" : $address;

        return [
            'allow_redirects' => false,
            'curl' => [
                CURLOPT_RESOLVE => ["{$resolveHost}:{$port}:{$resolveAddress}"],
                CURLOPT_MAXFILESIZE_LARGE => 5 * 1024 * 1024,
            ],
        ];
    }

    private static function isPublicIp(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }

        // Treat IPv4-mapped IPv6 addresses according to their embedded IPv4 address.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $address = inet_ntop(substr($packed, 12));
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
