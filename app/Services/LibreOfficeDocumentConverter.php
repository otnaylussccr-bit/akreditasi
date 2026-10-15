<?php

namespace App\Services;

use App\Contracts\OfficeDocumentConverter;
use App\Exceptions\OfficeDocumentPreviewException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class LibreOfficeDocumentConverter implements OfficeDocumentConverter
{
    private ?string $resolvedExecutable = null;

    public function availability(): array
    {
        if (! config('document-preview.enabled')) {
            return ['available' => false, 'reason' => 'Converter belum diaktifkan.'];
        }

        if (config('document-preview.security.require_network_isolation')
            && ! config('document-preview.security.network_isolated')) {
            return ['available' => false, 'reason' => 'Network isolation converter belum dikonfigurasi.'];
        }

        return $this->executable() !== null
            ? ['available' => true, 'reason' => 'Siap']
            : ['available' => false, 'reason' => 'Executable LibreOffice tidak ditemukan.'];
    }

    public function convert(string $inputPath, string $outputDirectory, string $extension): string
    {
        $availability = $this->availability();
        $executable = $this->executable();
        if (! $availability['available'] || $executable === null) {
            throw new OfficeDocumentPreviewException($availability['reason']);
        }

        $profileDirectory = dirname($outputDirectory).DIRECTORY_SEPARATOR.'profile';
        $temporaryDirectory = dirname($outputDirectory).DIRECTORY_SEPARATOR.'temp';
        $configDirectory = dirname($outputDirectory).DIRECTORY_SEPARATOR.'xdg-config';
        $cacheDirectory = dirname($outputDirectory).DIRECTORY_SEPARATOR.'xdg-cache';
        $runtimeDirectory = dirname($outputDirectory).DIRECTORY_SEPARATOR.'xdg-runtime';
        foreach ([$outputDirectory, $profileDirectory, $temporaryDirectory, $configDirectory, $cacheDirectory, $runtimeDirectory] as $directory) {
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new OfficeDocumentPreviewException('Temporary directory converter tidak dapat dibuat.', true);
            }
        }

        $filter = strtolower($extension) === 'xlsx'
            ? 'pdf:calc_pdf_Export:{"SinglePageSheets":{"type":"boolean","value":"false"}}'
            : 'pdf:writer_pdf_Export';
        $process = new Process([
            $executable,
            '--headless',
            '--nologo',
            '--nodefault',
            '--nofirststartwizard',
            '--norestore',
            '-env:UserInstallation='.$this->fileUri($profileDirectory),
            '--convert-to',
            $filter,
            '--outdir',
            $outputDirectory,
            $inputPath,
        ], dirname($outputDirectory), $this->sanitizedEnvironment(
            dirname($outputDirectory),
            $temporaryDirectory,
            $configDirectory,
            $cacheDirectory,
            $runtimeDirectory,
        ));
        $process->setTimeout((float) config('document-preview.process_timeout'));
        $process->disableOutput();

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException $exception) {
            throw new OfficeDocumentPreviewException('Konversi Office melewati batas waktu.', true, $exception);
        } catch (Throwable $exception) {
            throw new OfficeDocumentPreviewException('LibreOffice gagal mengonversi dokumen.', true, $exception);
        }

        $outputPath = $outputDirectory.DIRECTORY_SEPARATOR.pathinfo($inputPath, PATHINFO_FILENAME).'.pdf';
        if (! is_file($outputPath)) {
            throw new OfficeDocumentPreviewException('LibreOffice tidak menghasilkan PDF.', true);
        }

        return $outputPath;
    }

    private function executable(): ?string
    {
        if ($this->resolvedExecutable !== null) {
            return $this->resolvedExecutable;
        }

        $configured = config('document-preview.executable');
        $candidates = array_filter([
            is_string($configured) ? $configured : null,
            ...config('document-preview.discovery_paths', []),
        ]);

        foreach ($candidates as $candidate) {
            if ($this->isExecutable((string) $candidate)) {
                return $this->resolvedExecutable = (string) $candidate;
            }
        }

        $finder = new ExecutableFinder;
        foreach (config('document-preview.discovery_names', []) as $name) {
            $candidate = $finder->find((string) $name);
            if (is_string($candidate) && $this->isExecutable($candidate)) {
                return $this->resolvedExecutable = $candidate;
            }
        }

        return null;
    }

    private function isExecutable(string $path): bool
    {
        return is_file($path) && (PHP_OS_FAMILY === 'Windows' || is_executable($path));
    }

    private function fileUri(string $path): string
    {
        $path = str_replace('\\', '/', realpath($path) ?: $path);
        if (str_starts_with($path, '//')) {
            $segments = explode('/', ltrim($path, '/'));
            $host = array_shift($segments);

            return 'file://'.rawurlencode((string) $host).'/'.implode('/', array_map('rawurlencode', $segments));
        }

        $segments = explode('/', ltrim($path, '/'));
        if (PHP_OS_FAMILY === 'Windows' && isset($segments[0]) && preg_match('/^[a-z]:$/i', $segments[0]) === 1) {
            $drive = array_shift($segments);

            return 'file:///'.strtoupper((string) $drive).'/'.implode('/', array_map('rawurlencode', $segments));
        }

        return 'file:///'.implode('/', array_map('rawurlencode', $segments));
    }

    /** @return array<string, string|false> */
    private function sanitizedEnvironment(
        string $home,
        string $temporaryDirectory,
        string $configDirectory,
        string $cacheDirectory,
        string $runtimeDirectory,
    ): array {
        $inherited = getenv();
        $environment = is_array($inherited)
            ? array_fill_keys(array_keys($inherited), false)
            : [];

        foreach (['PATH', 'PATHEXT', 'SystemRoot', 'SYSTEMROOT', 'WINDIR', 'COMSPEC', 'LANG', 'LC_ALL'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        return array_merge($environment, [
            'HOME' => $home,
            'USERPROFILE' => $home,
            'TMP' => $temporaryDirectory,
            'TEMP' => $temporaryDirectory,
            'TMPDIR' => $temporaryDirectory,
            'XDG_CONFIG_HOME' => $configDirectory,
            'XDG_CACHE_HOME' => $cacheDirectory,
            'XDG_RUNTIME_DIR' => $runtimeDirectory,
            'SAL_DISABLE_OPENCL' => '1',
            'SAL_USE_VCLPLUGIN' => 'svp',
        ]);
    }
}
