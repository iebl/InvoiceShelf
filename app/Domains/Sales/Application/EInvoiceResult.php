<?php

namespace App\Domains\Sales\Application;

use InvalidArgumentException;

/**
 * What the E-Invoice builder returns: either XML that passed XSD validation or
 * the requirements the invoice does not meet yet, never both.
 */
final class EInvoiceResult
{
    /**
     * @param  list<EInvoiceRequirement>  $missingRequirements
     */
    private function __construct(
        private readonly ?string $xml,
        private readonly array $missingRequirements,
    ) {}

    /**
     * The invoice produced XSD-valid EN 16931 CII XML.
     */
    public static function valid(string $xml): self
    {
        return new self($xml, []);
    }

    /**
     * The invoice cannot become an E-Invoice yet, for the named reasons.
     *
     * @param  list<EInvoiceRequirement>  $missingRequirements
     */
    public static function incomplete(array $missingRequirements): self
    {
        if ($missingRequirements === []) {
            throw new InvalidArgumentException(
                'An incomplete E-Invoice result must name at least one missing requirement.'
            );
        }

        return new self(null, array_values($missingRequirements));
    }

    /**
     * Whether this invoice can be issued as an E-Invoice.
     */
    public function isValid(): bool
    {
        return $this->xml !== null;
    }

    /**
     * The EN 16931 CII XML, or null when requirements are missing.
     */
    public function xml(): ?string
    {
        return $this->xml;
    }

    /**
     * @return list<EInvoiceRequirement>
     */
    public function missingRequirements(): array
    {
        return $this->missingRequirements;
    }

    /**
     * The missing requirements as their stable string identifiers.
     *
     * @return list<string>
     */
    public function missingRequirementKeys(): array
    {
        return array_map(
            static fn (EInvoiceRequirement $requirement): string => $requirement->value,
            $this->missingRequirements
        );
    }
}
