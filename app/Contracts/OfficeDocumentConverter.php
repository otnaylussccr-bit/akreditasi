<?php

namespace App\Contracts;

interface OfficeDocumentConverter
{
    /** @return array{available: bool, reason: string} */
    public function availability(): array;

    public function convert(string $inputPath, string $outputDirectory, string $extension): string;
}
