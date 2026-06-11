<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Report;

/**
 * Minimal no-compression ZIP writer so bundles work even when ext-zip is absent.
 */
final class SimpleZipWriter
{
    /** @var resource */
    private $fp;

    /** @var array<int,array<string,int|string>> */
    private array $central = [];

    public function __construct(private readonly string $path)
    {
        $this->fp = fopen($path, 'wb');
        if (!$this->fp) {
            throw new \RuntimeException('Unable to create ZIP file: ' . $path);
        }
    }

    public function addFile(string $name, string $data): void
    {
        $name = str_replace('\\', '/', ltrim($name, '/'));
        $offset = ftell($this->fp);
        $crc = crc32($data);
        if ($crc < 0) {
            $crc += 4294967296;
        }
        $size = strlen($data);
        $nameLength = strlen($name);
        fwrite($this->fp, pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0));
        fwrite($this->fp, $name);
        fwrite($this->fp, $data);
        $this->central[] = [
            'name' => $name,
            'crc' => $crc,
            'size' => $size,
            'offset' => (int) $offset,
        ];
    }

    public function close(): void
    {
        $centralOffset = ftell($this->fp);
        foreach ($this->central as $entry) {
            $name = (string) $entry['name'];
            $nameLength = strlen($name);
            fwrite($this->fp, pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, (int) $entry['crc'], (int) $entry['size'], (int) $entry['size'], $nameLength, 0, 0, 0, 0, 0, (int) $entry['offset']));
            fwrite($this->fp, $name);
        }
        $centralSize = ftell($this->fp) - $centralOffset;
        $count = count($this->central);
        fwrite($this->fp, pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $centralSize, $centralOffset, 0));
        fclose($this->fp);
    }
}
