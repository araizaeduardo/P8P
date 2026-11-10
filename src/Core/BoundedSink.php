<?php

namespace P8P\Core;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Sink de Guzzle que corta la descarga al superar el límite, en la escritura de red y
 * antes de que el cuerpo (posiblemente comprimido) se acumule entero en memoria.
 */
final class BoundedSink implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;
    private int $limit = 1048576;
    private int $written = 0;
    private bool $exceeded = false;

    public static function create(int $limit): self
    {
        $sink = new self(Utils::streamFor(fopen('php://temp/maxmemory:' . min($limit + 1, 2097152), 'w+')));
        $sink->limit = $limit;
        return $sink;
    }

    public function exceeded(): bool
    {
        return $this->exceeded;
    }

    public function write($string): int
    {
        if ($this->written + strlen($string) > $this->limit) {
            $this->exceeded = true;
            throw new RuntimeException('Respuesta demasiado grande.');
        }
        $this->written += strlen($string);
        return $this->stream->write($string);
    }
}
