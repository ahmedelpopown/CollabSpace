<?php

namespace Modules\Files\Services;

use Illuminate\Http\UploadedFile;
use PharData;
use RecursiveIteratorIterator;
use Throwable;

class UploadedFileContentInspector
{
    private const MIME_TYPES = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'zip' => 'application/zip',
    ];

    private const OOXML_PARTS = [
        'docx' => [
            'word/document.xml',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
        ],
        'xlsx' => [
            'xl/workbook.xml',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
        ],
        'pptx' => [
            'ppt/presentation.xml',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml',
        ],
    ];

    private const IMAGE_EXTENSIONS = [
        'apng', 'avif', 'bmp', 'dib', 'gif', 'heic', 'heif', 'ico', 'jfif',
        'jpe', 'jpeg', 'jpg', 'jxl', 'png', 'psd', 'svg', 'svgz', 'tif', 'tiff', 'webp',
    ];

    /**
     * Return a trusted MIME type only when the file's bytes match an allowed
     * document format and its original extension. Images are never accepted.
     */
    public function detectDocumentMimeType(UploadedFile $upload, string $realPath): ?string
    {
        if (! is_file($realPath) || ! is_readable($realPath)) {
            return null;
        }

        $extension = strtolower($upload->getClientOriginalExtension());

        if ($extension === '' || in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return null;
        }

        $sample = @file_get_contents($realPath, false, null, 0, 16_384);

        if (! is_string($sample) || $sample === '' || $this->isImageContent($sample)) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = strtolower((string) $finfo->file($realPath));

        return match ($extension) {
            'pdf' => $this->isPdf($sample) ? self::MIME_TYPES['pdf'] : null,
            'doc', 'xls', 'ppt' => $this->isLegacyOfficeDocument($sample)
                ? self::MIME_TYPES[$extension]
                : null,
            'docx', 'xlsx', 'pptx' => $this->isOpenXmlDocument($realPath, $extension)
                ? self::MIME_TYPES[$extension]
                : null,
            'zip' => $this->isZipArchive($realPath, $sample, $detectedMime)
                ? self::MIME_TYPES['zip']
                : null,
            'txt' => $this->isTextDocument($sample, $detectedMime)
                ? self::MIME_TYPES['txt']
                : null,
            'csv' => $this->isTextDocument($sample, $detectedMime)
                ? self::MIME_TYPES['csv']
                : null,
            default => null,
        };
    }

    public function isImage(UploadedFile $upload, string $realPath): bool
    {
        $extension = strtolower($upload->getClientOriginalExtension());

        if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return true;
        }

        if (! is_file($realPath) || ! is_readable($realPath)) {
            return false;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = strtolower((string) $finfo->file($realPath));

        if (str_starts_with($mimeType, 'image/')) {
            return true;
        }

        $sample = @file_get_contents($realPath, false, null, 0, 16_384);

        return is_string($sample) && $this->isImageContent($sample);
    }

    private function isPdf(string $sample): bool
    {
        // The PDF specification permits a short prefix before the header.
        return strpos(substr($sample, 0, 1024), '%PDF-') !== false;
    }

    private function isLegacyOfficeDocument(string $sample): bool
    {
        // OLE Compound File signature used by legacy DOC, XLS and PPT files.
        return str_starts_with($sample, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
    }

    private function isOpenXmlDocument(string $realPath, string $extension): bool
    {
        if (! $this->hasZipSignature($realPath)) {
            return false;
        }

        [$requiredPart, $requiredContentType] = self::OOXML_PARTS[$extension];

        return $this->inspectZipArchive($realPath, function (PharData $archive, string $archivePath) use (
            $requiredPart,
            $requiredContentType,
        ): bool {
            $archivePrefix = 'phar://'.str_replace('\\', '/', $archivePath).'/';
            $hasContentTypes = false;
            $hasRequiredPart = false;
            $contentTypes = null;
            $entryCount = 0;

            foreach (new RecursiveIteratorIterator($archive) as $entry) {
                if (! $entry->isFile()) {
                    continue;
                }

                if (++$entryCount > 10_000) {
                    return false;
                }

                $entryPath = str_replace('\\', '/', $entry->getPathName());
                $relativePath = str_starts_with($entryPath, $archivePrefix)
                    ? substr($entryPath, strlen($archivePrefix))
                    : '';

                if ($relativePath === '[Content_Types].xml') {
                    if ($entry->getSize() > 1_048_576) {
                        return false;
                    }

                    $contentTypes = $entry->getContent();
                    $hasContentTypes = is_string($contentTypes);
                }

                if ($relativePath === $requiredPart) {
                    $hasRequiredPart = true;
                }
            }

            return $hasContentTypes
                && $hasRequiredPart
                && str_contains((string) $contentTypes, $requiredContentType);
        });
    }

    private function isZipArchive(string $realPath, string $sample, string $detectedMime): bool
    {
        if (! $this->hasZipSignatureFromSample($sample)
            && ! in_array($detectedMime, ['application/zip', 'application/x-zip-compressed'], true)) {
            return false;
        }

        return $this->inspectZipArchive($realPath, function (PharData $archive, string $archivePath): bool {
            foreach (new RecursiveIteratorIterator($archive) as $entry) {
                if ($entry->isFile()) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * PHP's PharData requires a recognised archive extension. UploadedFile temp
     * paths usually have no extension, so inspect a private temporary .zip copy.
     */
    private function inspectZipArchive(string $realPath, callable $inspector): bool
    {
        $temporaryDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'collabspace-zip-'.bin2hex(random_bytes(16));

        if (! @mkdir($temporaryDirectory, 0700)) {
            return false;
        }

        $archivePath = $temporaryDirectory.DIRECTORY_SEPARATOR.'archive.zip';

        try {
            if (! @copy($realPath, $archivePath)) {
                return false;
            }

            $archive = new PharData($archivePath);

            return $inspector($archive, $archivePath);
        } catch (Throwable) {
            return false;
        } finally {
            unset($archive);

            if (is_file($archivePath)) {
                @unlink($archivePath);
            }

            @rmdir($temporaryDirectory);
        }
    }

    private function isTextDocument(string $sample, string $detectedMime): bool
    {
        if (str_contains($sample, "\0")) {
            return false;
        }

        return str_starts_with($detectedMime, 'text/')
            || in_array($detectedMime, ['application/csv', 'application/vnd.ms-excel'], true);
    }

    private function isImageContent(string $sample): bool
    {
        foreach ([
            "\x89PNG\r\n\x1a\n",
            "\xff\xd8\xff",
            'GIF87a',
            'GIF89a',
            'BM',
            "II*\x00",
            "MM\x00*",
            "\x00\x00\x01\x00",
            '8BPS',
            "\xff\x0a",
            "\x00\x00\x00\x0cjP  \r\n\x87\n",
        ] as $signature) {
            if (str_starts_with($sample, $signature)) {
                return true;
            }
        }

        if (strlen($sample) >= 12
            && str_starts_with($sample, 'RIFF')
            && substr($sample, 8, 4) === 'WEBP') {
            return true;
        }

        if (strlen($sample) >= 12 && substr($sample, 4, 4) === 'ftyp') {
            $brands = substr($sample, 8, 80);

            foreach (['avif', 'avis', 'heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1'] as $brand) {
                if (str_contains($brands, $brand)) {
                    return true;
                }
            }
        }

        return $this->containsSvgDocument($sample);
    }

    private function containsSvgDocument(string $sample): bool
    {
        $sample = preg_replace('/^\xEF\xBB\xBF/', '', $sample) ?? $sample;
        $sample = preg_replace('/^\s*<\?xml\b.*?\?>\s*/is', '', $sample) ?? $sample;
        $sample = preg_replace('/^(?:\s*<!--.*?-->\s*)+/s', '', $sample) ?? $sample;
        $sample = preg_replace('/^\s*<!DOCTYPE\s+svg\b.*?>\s*/is', '', $sample) ?? $sample;

        return preg_match('/^\s*<(?:[a-z][a-z0-9_.-]*:)?svg(?=\s|>)/i', $sample) === 1;
    }

    private function hasZipSignature(string $realPath): bool
    {
        $sample = @file_get_contents($realPath, false, null, 0, 4);

        return is_string($sample) && $this->hasZipSignatureFromSample($sample);
    }

    private function hasZipSignatureFromSample(string $sample): bool
    {
        return str_starts_with($sample, "PK\x03\x04")
            || str_starts_with($sample, "PK\x05\x06")
            || str_starts_with($sample, "PK\x07\x08");
    }
}
