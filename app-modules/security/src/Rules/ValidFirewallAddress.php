<?php

declare(strict_types=1);

namespace Modules\Security\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Security\Support\AddressFamily;

/**
 * Validation rule for firewall address entry fields.
 *
 * Accepts IPv4 and IPv6 addresses of both families, optionally with CIDR
 * notation for the IP list forms. The manual ban dialog passes
 * allowCidr: false because kernel ban sets hold single addresses only.
 */
class ValidFirewallAddress implements ValidationRule
{
    /**
     * Create the rule instance.
     *
     * @param  string  $message  Localized validation message shown on failure
     * @param  bool  $allowCidr  Whether CIDR ranges are accepted
     */
    public function __construct(
        private readonly string $message,
        private readonly bool $allowCidr = true,
    ) {}

    /**
     * Run the validation rule against a single field value.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $candidate = trim((string) $value);

        $valid = $this->allowCidr
            ? AddressFamily::isValidAddressOrCidr($candidate)
            : AddressFamily::isValidAddress($candidate);

        if (! $valid) {
            $fail($this->message);
        }
    }
}
