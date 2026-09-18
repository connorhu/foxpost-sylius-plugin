<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin;

final class EligibilityResult
{
    /**
     * @param array<array{code: string, message: string}> $errors
     * @param array<array{code: string, message: string}> $warnings
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly array $errors = [],
        public readonly array $warnings = [],
    ) {
    }

    /**
     * @param array<array{code: string, message: string}> $warnings
     */
    public static function eligible(array $warnings = []): self
    {
        return new self(true, [], $warnings);
    }

    /**
     * @param array<array{code: string, message: string}> $errors
     * @param array<array{code: string, message: string}> $warnings
     */
    public static function ineligible(array $errors, array $warnings = []): self
    {
        return new self(false, $errors, $warnings);
    }
}
