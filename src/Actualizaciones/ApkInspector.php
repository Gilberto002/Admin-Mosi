<?php
declare(strict_types=1);

namespace App\Actualizaciones;

use RuntimeException;
use ZipArchive;

final class ApkInspector
{
    private const RES_VERSION_CODE = 0x0101021b;
    private const RES_VERSION_NAME = 0x0101021c;

    public static function inspeccionar(string $apkPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('El servidor no tiene habilitada la extension ZipArchive para leer el APK.');
        }

        $zip = new ZipArchive();
        if ($zip->open($apkPath) !== true) {
            throw new RuntimeException('No se pudo abrir el APK para leer su version.');
        }

        $manifest = $zip->getFromName('AndroidManifest.xml');
        $zip->close();
        if ($manifest === false || $manifest === '') {
            throw new RuntimeException('El APK no contiene AndroidManifest.xml.');
        }

        return self::leerManifestBinario($manifest);
    }

    private static function leerManifestBinario(string $xml): array
    {
        $strings = [];
        $resources = [];
        $versionCode = null;
        $versionName = null;

        $offset = 0;
        $len = strlen($xml);
        while ($offset + 8 <= $len) {
            $chunkType = self::u16($xml, $offset);
            $headerSize = self::u16($xml, $offset + 2);
            $chunkSize = self::u32($xml, $offset + 4);
            if ($chunkSize < 8 || $offset + $chunkSize > $len) {
                break;
            }

            if ($chunkType === 0x0003) {
                $offset += $headerSize;
                continue;
            }

            if ($chunkType === 0x0001) {
                $strings = self::leerStringPool($xml, $offset, $headerSize, $chunkSize);
            } elseif ($chunkType === 0x0180) {
                $resources = self::leerResourceMap($xml, $offset, $headerSize, $chunkSize);
            } elseif ($chunkType === 0x0102) {
                $attrs = self::leerStartElement($xml, $offset, $strings, $resources);
                foreach ($attrs as $attr) {
                    if ($attr['name'] === 'versionCode' || $attr['resource_id'] === self::RES_VERSION_CODE) {
                        $versionCode = (int) $attr['value'];
                    }
                    if ($attr['name'] === 'versionName' || $attr['resource_id'] === self::RES_VERSION_NAME) {
                        $versionName = trim((string) $attr['value']);
                    }
                }
                break;
            }

            $offset += $chunkSize;
        }

        if (!$versionCode || $versionName === null || $versionName === '') {
            throw new RuntimeException('No se pudo leer versionCode/versionName del AndroidManifest.xml.');
        }

        return [
            'version_code' => $versionCode,
            'version_name' => $versionName,
        ];
    }

    private static function leerStringPool(string $xml, int $offset, int $headerSize, int $chunkSize): array
    {
        $stringCount = self::u32($xml, $offset + 8);
        $flags = self::u32($xml, $offset + 16);
        $stringsStart = self::u32($xml, $offset + 20);
        $isUtf8 = ($flags & 0x00000100) !== 0;
        $strings = [];

        for ($i = 0; $i < $stringCount; $i++) {
            $stringOffset = self::u32($xml, $offset + $headerSize + ($i * 4));
            $pos = $offset + $stringsStart + $stringOffset;
            $strings[] = $isUtf8 ? self::leerUtf8($xml, $pos) : self::leerUtf16($xml, $pos);
        }

        return $strings;
    }

    private static function leerResourceMap(string $xml, int $offset, int $headerSize, int $chunkSize): array
    {
        $resources = [];
        $count = intdiv($chunkSize - $headerSize, 4);
        for ($i = 0; $i < $count; $i++) {
            $resources[$i] = self::u32($xml, $offset + $headerSize + ($i * 4));
        }
        return $resources;
    }

    private static function leerStartElement(string $xml, int $offset, array $strings, array $resources): array
    {
        $attrStart = self::u16($xml, $offset + 24);
        $attrSize = self::u16($xml, $offset + 26);
        $attrCount = self::u16($xml, $offset + 28);
        $attrs = [];

        for ($i = 0; $i < $attrCount; $i++) {
            $pos = $offset + 16 + $attrStart + ($i * $attrSize);
            $nameIdx = self::u32($xml, $pos + 4);
            $rawValueIdx = self::u32($xml, $pos + 8);
            $dataType = ord($xml[$pos + 15]);
            $data = self::u32($xml, $pos + 16);
            $name = $strings[$nameIdx] ?? '';
            $value = $rawValueIdx !== 0xffffffff ? ($strings[$rawValueIdx] ?? '') : self::typedValue($dataType, $data, $strings);
            $attrs[] = [
                'name' => $name,
                'resource_id' => $resources[$nameIdx] ?? null,
                'value' => $value,
            ];
        }

        return $attrs;
    }

    private static function typedValue(int $type, int $data, array $strings): mixed
    {
        if ($type === 0x03) {
            return $strings[$data] ?? '';
        }
        if (in_array($type, [0x10, 0x11], true)) {
            return $data;
        }
        return $data;
    }

    private static function leerUtf8(string $xml, int $pos): string
    {
        [, $pos] = self::leerLength8($xml, $pos);
        [$bytes, $pos] = self::leerLength8($xml, $pos);
        return substr($xml, $pos, $bytes);
    }

    private static function leerUtf16(string $xml, int $pos): string
    {
        [$chars, $pos] = self::leerLength16($xml, $pos);
        $raw = substr($xml, $pos, $chars * 2);
        $texto = @iconv('UTF-16LE', 'UTF-8//IGNORE', $raw);
        return $texto !== false ? $texto : '';
    }

    private static function leerLength8(string $xml, int $pos): array
    {
        $len = ord($xml[$pos++]);
        if (($len & 0x80) !== 0) {
            $len = (($len & 0x7f) << 8) | ord($xml[$pos++]);
        }
        return [$len, $pos];
    }

    private static function leerLength16(string $xml, int $pos): array
    {
        $len = self::u16($xml, $pos);
        $pos += 2;
        if (($len & 0x8000) !== 0) {
            $len = (($len & 0x7fff) << 16) | self::u16($xml, $pos);
            $pos += 2;
        }
        return [$len, $pos];
    }

    private static function u16(string $data, int $offset): int
    {
        return unpack('v', substr($data, $offset, 2))[1];
    }

    private static function u32(string $data, int $offset): int
    {
        return unpack('V', substr($data, $offset, 4))[1];
    }
}
