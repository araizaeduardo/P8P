<?php

namespace P8P\Nodes\Custom;

use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use P8P\Core\TriggerRetryException;
use P8P\Interfaces\NodeInterface;
use P8P\Interfaces\TriggerProviderInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/** Public feeds only; no credentials, HTML execution, external entities, or background daemon. */
class RssAtomNode implements NodeInterface, TriggerProviderInterface
{
    private ?\Closure $resolver;
    private const MAX_BYTES = 2097152;

    public function __construct(private ?ClientInterface $client = null, ?callable $resolver = null)
    {
        $this->resolver = $resolver === null ? null : \Closure::fromCallable($resolver);
    }

    public function getSchema(): array
    {
        return ['type' => 'rss_atom', 'name' => 'RSS / Atom Feed Reader', 'category' => 'Community',
            'description' => 'Lee feeds públicos RSS 2.0, RSS 1.0 y Atom con límites y polling persistente.',
            'icon' => 'Rss', 'is_start' => false,
            'help_text' => 'Solo HTTPS público sin credenciales, puerto 443 y sin redirecciones. Máximo 2 MiB/500 artículos por documento; limit devuelve los primeros 1–100 e indica si hay más. HTML es contenido no confiable y no se ejecuta. Configura eventos nuevos o modificados desde Triggers; primera lectura establece baseline sin emitir histórico.',
            'parameters' => ['feed_url' => ['type' => 'string', 'required' => true, 'label' => 'URL HTTPS pública del feed'],
                'limit' => ['type' => 'number', 'required' => false, 'default' => 20, 'label' => 'Artículos devueltos (1–100)']]];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->url($parameters['feed_url'] ?? null);
            $limit = $parameters['limit'] ?? 20;
            return !is_bool($limit) && is_scalar($limit) && filter_var($limit, FILTER_VALIDATE_INT) !== false && (int)$limit >= 1 && (int)$limit <= 100;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        if (!$this->validate($parameters)) {
            throw new InvalidArgumentException('RSS/Atom: URL o límite inválido.');
        }
        $feed = $this->fetch($parameters['feed_url'], [], false);
        $limit = (int)($parameters['limit'] ?? 20);
        return ['success' => true, 'format' => $feed['format'], 'title' => $feed['title'],
            'items' => array_slice($feed['items'], 0, $limit), 'total_items' => count($feed['items']),
            'has_more' => count($feed['items']) > $limit, 'etag' => $feed['etag'], 'last_modified' => $feed['last_modified']];
    }

    public function definitions(): array
    {
        $definitions = [];
        foreach (['rss_atom_new_item' => ['Artículo nuevo', 'feed.item.created'], 'rss_atom_item_changed' => ['Artículo modificado', 'feed.item.updated']] as $key => [$name, $event]) {
            $definitions[$key] = ['name' => 'RSS/Atom: ' . $name, 'node_type' => 'rss_atom', 'event' => $event,
                'transport' => 'polling', 'config_fields' => ['feed_url' => ['type' => 'string', 'label' => 'URL HTTPS pública', 'required' => true]],
                'secret_fields' => [], 'help' => 'Sin credencial. Baseline sin histórico; hasta 500 artículos/2 MiB, lotes de 100, memoria de 5000 IDs. Cambios se comparan por contenido normalizado; no detecta eliminaciones ni entradas que aparezcan y desaparezcan entre lecturas. URLs sin redirecciones ni tokens.',
                'docs' => ['https://www.rssboard.org/rss-2-0-11', 'https://www.rfc-editor.org/rfc/rfc4287.html']];
        }
        return $definitions;
    }

    public function verify(string $key, array $config, array $secrets, array $request, int $now): array
    {
        throw new \LogicException('RSS/Atom utiliza polling, no admite webhooks.');
    }

    public function poll(string $key, array $config, array $secrets, array $state, int $now): array
    {
        if (!isset($this->definitions()[$key])) {
            throw new InvalidArgumentException('RSS/Atom: trigger desconocido.');
        }
        $url = $config['feed_url'] ?? null;
        $this->url($url);
        $binding = hash('sha256', $key . "\0" . $url);
        if (isset($state['binding']) && !hash_equals($binding, $state['binding'])) {
            throw new InvalidArgumentException('RSS/Atom: URL cambiada; reactiva el trigger para establecer baseline.');
        }
        $state['binding'] = $binding;
        if (!empty($state['pending'])) {
            $events = array_splice($state['pending'], 0, 100);
            return ['events' => $events, 'state' => $state, 'has_more' => $state['pending'] !== []];
        }
        $feed = $this->fetch($url, $state, true);
        if ($feed['not_modified']) {
            return ['events' => [], 'state' => $state, 'has_more' => false];
        }
        $seen = $state['seen'] ?? [];
        $events = [];
        foreach ($feed['items'] as $item) {
            $id = hash('sha256', $item['id']);
            $hash = hash('sha256', json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $previous = $seen[$id] ?? null;
            $revision = ($previous['revision'] ?? 0) + (int)($previous === null || $previous['hash'] !== $hash);
            if (!empty($state['initialized']) && (($key === 'rss_atom_new_item' && $previous === null)
                || ($key === 'rss_atom_item_changed' && $previous !== null && $previous['hash'] !== $hash))) {
                $events[] = ['id' => hash('sha256', $id . ':' . $revision . ':' . $hash),
                    'type' => $this->definitions()[$key]['event'], 'data' => $item];
            }
            // Reinsert every observed ID: insertion order gives a bounded LRU set.
            unset($seen[$id]);
            $seen[$id] = ['hash' => $hash, 'revision' => $revision];
        }
        $state['seen'] = array_slice($seen, -5000, null, true);
        $state['initialized'] = true;
        $state['etag'] = $feed['etag'];
        $state['last_modified'] = $feed['last_modified'];
        $state['pending'] = array_slice($events, 100);
        return ['events' => array_slice($events, 0, 100), 'state' => $state, 'has_more' => $state['pending'] !== []];
    }

    /** Validate URL without DNS or other side effects, including at discovery time. */
    private function url(mixed $url): array
    {
        if (!is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('RSS/Atom: URL HTTPS inválida.');
        }
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ($parts['port'] ?? 443) !== 443 || !preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/D', $host)
            || !str_contains($host, '.') || preg_match('/(?:^|\.)(?:localhost|local|internal|test|invalid|example)$/D', $host)) {
            throw new InvalidArgumentException('RSS/Atom: solo HTTPS público sin credenciales, fragmentos o puertos alternativos.');
        }
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach ($query as $name => $value) {
                if (!in_array($name, ['feed', 'format', 'type', 'lang', 'language', 'page', 'limit'], true)
                    || !is_string($value) || strlen($value) > 128 || preg_match('/[^A-Za-z0-9_.\-]/', $value)) {
                    throw new InvalidArgumentException('RSS/Atom: query no admitida; no se permiten URLs con claves o tokens.');
                }
            }
        }
        return [$host, $parts];
    }

    private function address(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } elseif ($this->resolver !== null) {
            try {
                $addresses = ($this->resolver)($host);
            } catch (\Throwable $e) {
                throw new RuntimeException('RSS/Atom: no se pudo resolver el host del feed.');
            }
        } else {
            $addresses = [];
            $pending = [$host];
            $visited = [];
            while ($pending !== [] && count($visited) < 6) {
                $name = array_shift($pending);
                if (isset($visited[$name])) {
                    continue;
                }
                $visited[$name] = true;
                foreach (@dns_get_record($name, DNS_A | DNS_AAAA | DNS_CNAME) ?: [] as $record) {
                    if (isset($record['ip']) || isset($record['ipv6'])) {
                        $addresses[] = $record['ip'] ?? $record['ipv6'];
                    } elseif (isset($record['target']) && ($record['type'] ?? '') === 'CNAME') {
                        $pending[] = $record['target'];
                    }
                }
            }
            if ($pending !== []) {
                throw new RuntimeException('RSS/Atom: cadena DNS demasiado larga.');
            }
        }
        if (!is_array($addresses) || $addresses === [] || count($addresses) > 64) {
            throw new RuntimeException('RSS/Atom: no se pudo resolver una dirección pública.');
        }
        foreach ($addresses as $ip) {
            if (!is_string($ip) || !$this->publicIp($ip)) {
                throw new InvalidArgumentException('RSS/Atom: DNS apunta a una dirección no pública.');
            }
        }
        return $addresses[0];
    }

    private function publicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $packed = inet_pton($ip);
        if (strlen($packed) === 16) {
            // Global unicast only; exclude documentation, transition and special-use ranges.
            if ((ord($packed[0]) & 0xe0) !== 0x20) {
                return false;
            }
            $ranges = ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];
        } else {
            $ranges = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
                '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
                '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];
        }
        foreach ($ranges as $range) {
            [$base, $bits] = explode('/', $range);
            $network = inet_pton($base);
            $bytes = intdiv((int)$bits, 8);
            $remainder = (int)$bits % 8;
            if (substr($packed, 0, $bytes) === substr($network, 0, $bytes)
                && ($remainder === 0 || (ord($packed[$bytes]) & (0xff << (8 - $remainder))) === (ord($network[$bytes]) & (0xff << (8 - $remainder))))) {
                return false;
            }
        }
        return true;
    }

    private function fetch(string $url, array $state, bool $polling): array
    {
        [$host] = $this->url($url);
        $ip = $this->address($host);
        if (!extension_loaded('curl') || !class_exists(DOMDocument::class)) {
            throw new RuntimeException('RSS/Atom requiere extensiones PHP curl y dom.');
        }
        $headers = ['Accept' => 'application/atom+xml, application/rss+xml, application/rdf+xml, application/xml, text/xml', 'User-Agent' => 'P8P-FeedReader/1.0'];
        foreach (['etag' => 'If-None-Match', 'last_modified' => 'If-Modified-Since'] as $field => $header) {
            if (!empty($state['initialized']) && is_string($state[$field] ?? null) && strlen($state[$field]) <= 512 && !preg_match('/[\x00-\x1f\x7f]/', $state[$field])) {
                $headers[$header] = $state[$field];
            }
        }
        // Bound decompressed bytes at the network sink, before Guzzle can buffer a large body.
        $sink = new class(Utils::streamFor(fopen('php://temp/maxmemory:2097152', 'w+'))) implements StreamInterface {
            use StreamDecoratorTrait;
            private StreamInterface $stream;
            private int $written = 0;
            public function write($string): int
            {
                if ($this->written + strlen($string) > 2097152) {
                    throw new RuntimeException('Feed demasiado grande.');
                }
                $this->written += strlen($string);
                return $this->stream->write($string);
            }
        };
        try {
            $response = ($this->client ?? new Client(['handler' => HandlerStack::create(new CurlHandler())]))->request('GET', $url, [
                'headers' => $headers, 'timeout' => 30, 'connect_timeout' => 10, 'verify' => true, 'allow_redirects' => false,
                'http_errors' => false, 'proxy' => '', 'sink' => $sink,
                'curl' => [CURLOPT_RESOLVE => [$host . ':443:' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)], CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]
            ]);
        } catch (\Throwable $e) {
            $sink->close();
            if ($polling) {
                throw new TriggerRetryException(60);
            }
            throw new RuntimeException('RSS/Atom: fallo de lectura o tamaño excedido; no se reintenta automáticamente.');
        }
        $status = $response->getStatusCode();
        if ($status === 304 && !empty($state['initialized'])) {
            $response->getBody()->close();
            return ['not_modified' => true];
        }
        if ($polling && ($status === 429 || $status >= 500)) {
            $retry = $response->getHeaderLine('Retry-After');
            $response->getBody()->close();
            throw new TriggerRetryException(ctype_digit($retry) ? min(86400, max(60, (int)$retry)) : 60);
        }
        if ($status !== 200) {
            $response->getBody()->close();
            throw new RuntimeException('RSS/Atom: el feed no devolvió HTTP 200; no se siguen redirecciones.');
        }
        $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
        if (!in_array($type, ['application/rss+xml', 'application/atom+xml', 'application/rdf+xml', 'application/xml', 'text/xml'], true)) {
            $response->getBody()->close();
            throw new RuntimeException('RSS/Atom: Content-Type XML no admitido.');
        }
        $stream = ($sink->getSize() ?? 0) > 0 ? $sink : $response->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $raw = '';
        try {
            while (!$stream->eof() && strlen($raw) <= self::MAX_BYTES) {
                $chunk = $stream->read(min(65536, self::MAX_BYTES + 1 - strlen($raw)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException('Lectura incompleta.');
                }
                $raw .= $chunk;
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('RSS/Atom: respuesta ilegible.');
        } finally {
            $stream->close();
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw new RuntimeException('RSS/Atom: el feed supera 2 MiB.');
        }
        $feed = $this->parse($raw, $url);
        $feed['not_modified'] = false;
        foreach (['ETag' => 'etag', 'Last-Modified' => 'last_modified'] as $header => $field) {
            $value = $response->getHeaderLine($header);
            $feed[$field] = strlen($value) <= 512 && !preg_match('/[\x00-\x1f\x7f]/', $value) ? $value : '';
        }
        return $feed;
    }

    private function parse(string $raw, string $url): array
    {
        if ($raw === '' || str_contains($raw, "\0") || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $raw)) {
            throw new RuntimeException('RSS/Atom: DTD, entidades y XML con bytes nulos no están permitidos.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (!$document->loadXML($raw, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT) || $document->doctype !== null) {
                throw new RuntimeException('RSS/Atom: XML inválido.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('a', 'http://www.w3.org/2005/Atom');
        $xpath->registerNamespace('r', 'http://purl.org/rss/1.0/');
        $xpath->registerNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
        $xpath->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
        $xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $xpath->registerNamespace('xi', 'http://www.w3.org/2001/XInclude');
        if ($xpath->query('//xi:*')->length > 0) {
            throw new RuntimeException('RSS/Atom: XInclude no permitido.');
        }
        $root = $document->documentElement;
        if ($root->localName === 'feed' && $root->namespaceURI === 'http://www.w3.org/2005/Atom') {
            $format = 'atom'; $nodes = $xpath->query('/a:feed/a:entry'); $title = $xpath->evaluate('string(/a:feed/a:title)');
        } elseif ($root->nodeName === 'rss' && $root->namespaceURI === null && $root->getAttribute('version') === '2.0' && $xpath->query('/rss/channel')->length === 1) {
            $format = 'rss2'; $nodes = $xpath->query('/rss/channel/item'); $title = $xpath->evaluate('string(/rss/channel/title)');
        } elseif ($root->localName === 'RDF' && $root->namespaceURI === 'http://www.w3.org/1999/02/22-rdf-syntax-ns#') {
            $format = 'rss1'; $nodes = $xpath->query('/rdf:RDF/r:item'); $title = $xpath->evaluate('string(/rdf:RDF/r:channel/r:title)');
        } else {
            throw new RuntimeException('RSS/Atom: formato no admitido; usa RSS 2.0, RSS 1.0 RDF o Atom 1.0.');
        }
        if ($nodes->length > 500) {
            throw new RuntimeException('RSS/Atom: el documento supera 500 artículos; usa un feed de menor tamaño.');
        }
        $items = [];
        foreach ($nodes as $node) {
            $text = static fn (string $path): string => trim($xpath->evaluate('string(' . $path . ')', $node));
            if ($format === 'atom') {
                $id = $text('a:id'); $itemTitle = $text('a:title');
                $linkNode = $xpath->query('a:link[not(@rel) or @rel="alternate"][@href]', $node)->item(0);
                $link = $linkNode instanceof DOMElement ? $this->link($linkNode->getAttribute('href'), $linkNode, $url) : '';
                $summary = $text('a:summary'); $content = $text('a:content');
                $published = $text('a:published'); $updated = $text('a:updated');
            } else {
                $prefix = $format === 'rss1' ? 'r:' : '';
                $id = $format === 'rss1' ? $node->getAttributeNS('http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'about') : $text('guid');
                $itemTitle = $text($prefix . 'title'); $link = $this->link($text($prefix . 'link'), $node, $url);
                $summary = $text($prefix . 'description'); $content = $text('content:encoded');
                $published = $text($prefix . 'pubDate') ?: $text('dc:date'); $updated = $text('a:updated');
            }
            if ($id === '') {
                $id = $link !== '' ? $link : 'fallback:' . hash('sha256', $itemTitle . "\0" . $published);
            }
            if (strlen($id) > 4096 || ($itemTitle === '' && $summary === '' && $content === '')) {
                throw new RuntimeException('RSS/Atom: artículo sin contenido utilizable o ID demasiado grande.');
            }
            $item = ['id' => $id, 'title' => $itemTitle, 'link' => $link, 'content' => $content, 'summary' => $summary,
                'published' => $this->date($published), 'updated' => $this->date($updated)];
            $idKey = hash('sha256', $id);
            // Duplicate IDs represent one entry; keep the newest dated revision when available.
            if (!isset($items[$idKey]) || ($item['updated'] ?? $item['published'] ?? '') >= ($items[$idKey]['updated'] ?? $items[$idKey]['published'] ?? '')) {
                $items[$idKey] = $item;
            }
        }
        return ['format' => $format, 'title' => trim($title), 'items' => array_values($items)];
    }

    private function link(string $value, DOMElement $element, string $url): string
    {
        if ($value === '') {
            return '';
        }
        try {
            $bases = [];
            for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
                if ($node->hasAttributeNS('http://www.w3.org/XML/1998/namespace', 'base')) {
                    array_unshift($bases, $node->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'base'));
                }
            }
            $base = new Uri($url);
            foreach ($bases as $relative) {
                $base = UriResolver::resolve($base, new Uri($relative));
            }
            $resolved = UriResolver::resolve($base, new Uri($value));
            return in_array($resolved->getScheme(), ['http', 'https'], true) && $resolved->getUserInfo() === '' ? (string)$resolved : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function date(string $value): ?string
    {
        if ($value === '' || strlen($value) > 128
            || (!preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2}))?$/D', $value)
                && !preg_match('/^(?:(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\s+)?\d{1,2}\s+[A-Za-z]{3}\s+\d{2,4}\s+\d{2}:\d{2}(?::\d{2})?\s+(?:[A-Za-z]{1,5}|[+-]\d{4})$/D', $value))) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            $errors = \DateTimeImmutable::getLastErrors();
            return is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)
                ? null : $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
