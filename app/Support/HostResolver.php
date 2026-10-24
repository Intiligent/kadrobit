<?php

namespace App\Support;

/**
 * Resolves user-entered server names. Kadrobit signs in to these servers itself, so a name that leads
 * to a private or reserved address would let a user probe Kadrobit's own network.
 */
class HostResolver
{
    /**
     * A host name, or an IPv4 or IPv6 address.
     */
    public function isValidName(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /**
     * IPv4 and IPv6 addresses of the host; the address itself for an IP literal; empty when it does not resolve.
     *
     * @return list<string>
     */
    public function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        // gethostbynamel() goes through the system resolver (hosts file included) but returns IPv4 only.
        $ipv4 = gethostbynamel($host) ?: [];
        $ipv6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique([...$ipv4, ...$ipv6]));
    }

    /**
     * Not private, loopback, link-local, carrier-grade NAT or documentation ranges.
     */
    public function isPublic(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    /**
     * The address to connect to (IPv4 first), or null when the host does not resolve or any of its
     * addresses is not public. Connecting to this address, not to the name, keeps a DNS answer that
     * changes after the check from redirecting the connection.
     */
    public function publicAddress(string $host): ?string
    {
        $addresses = $this->addresses($host);

        if ($addresses === [] || array_filter($addresses, fn (string $address) => ! $this->isPublic($address)) !== []) {
            return null;
        }

        usort($addresses, fn (string $a, string $b) => str_contains($a, ':') <=> str_contains($b, ':'));

        return $addresses[0];
    }
}
