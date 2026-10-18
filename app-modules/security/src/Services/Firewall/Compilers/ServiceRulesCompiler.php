<?php

declare(strict_types=1);

namespace Modules\Security\Services\Firewall\Compilers;

use Illuminate\Database\Eloquent\Collection;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecurityService;

/**
 * Compiles port catalog system services (stage 10), custom sequential
 * rules (stage 11), and default inbound policy (stage 12).
 */
class ServiceRulesCompiler
{
    public function __construct(
        protected BlocklistCompiler $blocklistCompiler = new BlocklistCompiler,
    ) {}

    /**
     * Compile stages 10–12 into ruleset lines.
     *
     * @return array<int, string>
     */
    public function compileStages(bool $observeMode, string $defaultPolicy): array
    {
        return array_merge(
            $this->compileSystemServices(),
            $this->compileCustomRules(null, $observeMode),
            $this->compileDefaultPolicy($observeMode, $defaultPolicy),
        );
    }

    /**
     * Compile STAGE 10: System PBX services from port catalog.
     *
     * @param  Collection<int, SecurityService>|null  $systemServices
     * @return array<int, string>
     */
    public function compileSystemServices(?Collection $systemServices = null): array
    {
        $systemServices ??= SecurityService::system()->active()->where('protocol', '!=', 'icmp')->get();

        $lines = [];
        $lines[] = '        # STAGE 10: CORE PBX TELEPHONY PORTS';

        foreach ($systemServices as $service) {
            $lines[] = "        # Service: {$service->name}";
            $prefix = '';
            $source = trim((string) ($service->source_ip ?? 'any'));
            if ($source !== '' && $source !== 'any' && $source !== '0.0.0.0/0') {
                $prefix = "ip saddr {$source} ";
            }

            $formattedPort = $this->formatPortRange($service->port_range);
            foreach ($this->generateServiceRules($service->protocol, $formattedPort, 'accept', $prefix) as $ruleLine) {
                $lines[] = "        {$ruleLine}";
            }
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * Compile STAGE 11: Custom sequential rules.
     *
     * @param  Collection<int, SecurityRule>|null  $customRules
     * @return array<int, string>
     */
    public function compileCustomRules(?Collection $customRules = null, bool $observeMode = false): array
    {
        $customRules ??= SecurityRule::ordered()->active()->with('service')->get();

        $lines = [];
        $lines[] = '        # STAGE 11: CUSTOM SEQUENTIAL RULES';

        foreach ($customRules as $rule) {
            $lines[] = "        # Rule {$rule->sequence}: {$rule->description}";
            $prefix = '';
            $source = trim($rule->source_ip);
            if ($source !== '' && $source !== 'any' && $source !== '0.0.0.0/0') {
                $prefix = "ip saddr {$source} ";
            }

            $action = (strtolower($rule->action) === 'drop' || strtolower($rule->action) === 'block')
                ? $this->blocklistCompiler->dropStatement('custom', $observeMode)
                : 'accept';

            if ($rule->service !== null) {
                $proto = $rule->service->protocol;
                $rawPort = $rule->service->port_range;
            } else {
                $proto = $rule->custom_protocol ?? 'tcp';
                $rawPort = $rule->custom_port ?? '';
            }

            $formattedPort = $this->formatPortRange($rawPort);
            foreach ($this->generateServiceRules($proto, $formattedPort, $action, $prefix) as $ruleLine) {
                $lines[] = "        {$ruleLine}";
            }
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * Compile STAGE 12: Default inbound policy enforcement.
     *
     * @return array<int, string>
     */
    public function compileDefaultPolicy(bool $observeMode, string $defaultPolicy): array
    {
        return [
            '        # STAGE 12: DEFAULT INBOUND POLICY',
            '        '.($observeMode ? 'accept' : $defaultPolicy),
        ];
    }

    /**
     * Format port ranges and multiple ports for nftables syntax.
     *
     * Converts colon ranges (16384:32768) to hyphens (16384-32768) and comma-separated
     * port lists (80,443) to nftables braces ({ 80, 443 }).
     */
    public function formatPortRange(string $rawPort): string
    {
        $port = trim($rawPort);

        if ($port === '') {
            return '';
        }

        // Colon range: 16384:32768 -> 16384-32768
        if (str_contains($port, ':')) {
            return str_replace(':', '-', $port);
        }

        // Comma-separated: 5060,5061,5080 -> { 5060, 5061, 5080 }
        if (str_contains($port, ',')) {
            $parts = array_filter(array_map('trim', explode(',', $port)));

            return '{ '.implode(', ', $parts).' }';
        }

        return $port;
    }

    /**
     * Build the concise nftables match / selector expression for a service or rule.
     */
    public function formatServiceNftablesRule(?string $protocol, ?string $portRaw = null): string
    {
        $proto = strtolower(trim((string) ($protocol ?? 'tcp')));
        $portFormatted = $this->formatPortRange(trim((string) ($portRaw ?? '')));
        $portSuffix = $portFormatted !== '' ? " dport {$portFormatted}" : '';

        if ($proto === 'icmp') {
            return 'icmp type echo-request';
        }

        if ($proto === 'both' || $proto === 'all') {
            return $portSuffix !== '' ? "{ tcp, udp }{$portSuffix}" : 'meta l4proto { tcp, udp }';
        }

        if ($proto === 'udp') {
            return $portSuffix !== '' ? "udp{$portSuffix}" : 'ip protocol udp';
        }

        return $portSuffix !== '' ? "tcp{$portSuffix}" : 'ip protocol tcp';
    }

    /**
     * Generate nftables rules for a specific protocol and port specification.
     *
     * @return array<int, string> Generated rule lines
     */
    public function generateServiceRules(
        string $protocol,
        string $portFormatted,
        string $action = 'accept',
        string $prefix = '',
    ): array {
        $lines = [];
        $proto = strtolower(trim($protocol));
        $portSuffix = $portFormatted !== '' ? "dport {$portFormatted} " : '';

        if ($proto === 'both') {
            $lines[] = "{$prefix}udp {$portSuffix}{$action}";
            $lines[] = "{$prefix}tcp {$portSuffix}{$action}";
        } elseif ($proto === 'udp') {
            $lines[] = "{$prefix}udp {$portSuffix}{$action}";
        } elseif ($proto === 'tcp') {
            $lines[] = "{$prefix}tcp {$portSuffix}{$action}";
        } elseif ($proto === 'icmp') {
            $lines[] = "{$prefix}ip protocol icmp icmp type echo-request {$action}";
        } else {
            $lines[] = "{$prefix}tcp {$portSuffix}{$action}";
        }

        return $lines;
    }
}
