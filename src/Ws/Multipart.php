<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws;

/** Construye un cuerpo multipart/form-data (sin depender de symfony/mime). */
final class Multipart
{
    /**
     * @param array<string, string> $fields
     *
     * @return array{0: string, 1: string} [content-type, body]
     */
    public static function build(array $fields, string $fileField, string $fileName, string $fileContent, string $fileContentType = 'application/octet-stream'): array
    {
        $boundary = '----SiiPhp'.bin2hex(random_bytes(12));
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$fileField}\"; filename=\"{$fileName}\"\r\n"
            ."Content-Type: {$fileContentType}\r\nContent-Transfer-Encoding: binary\r\n\r\n{$fileContent}\r\n";
        $body .= "--{$boundary}--\r\n";

        return ['multipart/form-data; boundary='.$boundary, $body];
    }
}
