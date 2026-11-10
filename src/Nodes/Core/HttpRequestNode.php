<?php

namespace P8P\Nodes\Core;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use InvalidArgumentException;
use P8P\Core\BoundedSink;
use P8P\Core\UrlGuard;
use P8P\Interfaces\NodeInterface;
use RuntimeException;
use Throwable;

class HttpRequestNode implements NodeInterface
{
    /** Tope de la respuesta descargada (1 MiB). */
    private const MAX_RESPONSE_BYTES = 1048576;

    private UrlGuard $guard;
    private ?Client $client;

    /** Argumentos opcionales: NodeRegistry instancia el nodo sin ellos; las pruebas los inyectan. */
    public function __construct(?UrlGuard $guard = null, ?Client $client = null)
    {
        $this->guard = $guard ?? new UrlGuard();
        $this->client = $client;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'http_request',
            'name' => 'HTTP Request',
            'category' => 'Core',
            'description' => 'Realiza peticiones HTTP (GET, POST, PUT, DELETE) a servicios externos.',
            'icon' => 'Globe',
            'is_start' => false,
            'help_text' => 'Permite conectar tu flujo con APIs web. Puedes usar sintaxis {{variable}} en la URL, Headers o Body para enviar datos dinámicos.',
            'parameters' => [
                'url' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'URL de Destino',
                    'placeholder' => 'https://api.ejemplo.com/v1/data'
                ],
                'method' => [
                    'type' => 'select',
                    'required' => true,
                    'label' => 'Método HTTP',
                    'default' => 'GET',
                    'options' => [
                        ['value' => 'GET', 'label' => 'GET'],
                        ['value' => 'POST', 'label' => 'POST'],
                        ['value' => 'PUT', 'label' => 'PUT'],
                        ['value' => 'DELETE', 'label' => 'DELETE']
                    ]
                ],
                'headers' => [
                    'type' => 'textarea',
                    'required' => false,
                    'label' => 'Encabezados (JSON)',
                    'placeholder' => "{\n  \"Content-Type\": \"application/json\"\n}"
                ],
                'body' => [
                    'type' => 'textarea',
                    'required' => false,
                    'label' => 'Cuerpo de la Petición (Body)',
                    'placeholder' => "{\n  \"key\": \"{{value}}\"\n}"
                ]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        return !empty($parameters['url']);
    }

    public function execute(array $parameters, array $context): array
    {
        $url = $parameters['url'] ?? '';
        $method = strtoupper($parameters['method'] ?? 'GET');
        $rawHeaders = $parameters['headers'] ?? '';
        $rawBody = $parameters['body'] ?? '';

        if (!is_string($url) || !in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new InvalidArgumentException('HTTP Request: URL o método inválido.');
        }

        // SSRF: el destino debe resolver solo a direcciones públicas. La conexión se fija a la
        // IP validada para que un segundo lookup no pueda devolver otra (DNS rebinding).
        try {
            $target = $this->guard->check($url);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('HTTP Request: destino no permitido. ' . $e->getMessage());
        } catch (RuntimeException $e) {
            throw new RuntimeException('HTTP Request: ' . $e->getMessage());
        }

        $client = $this->client ?? new Client(['handler' => HandlerStack::create(new CurlHandler())]);
        $sink = BoundedSink::create(self::MAX_RESPONSE_BYTES);
        $curl = [CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS];
        if ($target['resolve'] !== null) {
            $curl[CURLOPT_RESOLVE] = [$target['resolve']];
        }
        $options = [
            'timeout' => 30,
            'connect_timeout' => 10,
            'read_timeout' => 30,
            'verify' => true,
            // Sin redirects (cada salto necesitaría su propia validación) y sin proxy de entorno,
            // que anularía la IP fijada.
            'allow_redirects' => false,
            'proxy' => '',
            'http_errors' => false,
            'sink' => $sink,
            'curl' => $curl,
        ];

        // 1. Procesar Headers
        $headers = [];
        if (!empty($rawHeaders)) {
            if (is_array($rawHeaders)) {
                $headers = $rawHeaders;
            } elseif (is_string($rawHeaders)) {
                $decoded = json_decode($rawHeaders, true);
                if (is_array($decoded)) {
                    $headers = $decoded;
                }
            }
        }
        if (!empty($headers)) {
            $options['headers'] = $headers;
        }

        // 2. Procesar Body (POST, PUT, PATCH)
        if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($rawBody)) {
            if (is_array($rawBody)) {
                $options['json'] = $rawBody;
            } elseif (is_string($rawBody)) {
                $decodedBody = json_decode($rawBody, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedBody)) {
                    // Si el cuerpo es un JSON válido, se envía mediante la opción 'json' de Guzzle
                    $options['json'] = $decodedBody;
                } else {
                    $options['body'] = $rawBody;
                }
            }
        }

        try {
            $response = $client->request($method, $url, $options);
        } catch (Throwable $e) {
            // El mensaje original puede incluir la URL completa (con tokens en la query): no se propaga.
            if ($sink->exceeded()) {
                throw new RuntimeException('HTTP Request: la respuesta supera el límite de 1 MiB.');
            }
            throw new RuntimeException('HTTP Request: fallo de transporte (' . (new \ReflectionClass($e))->getShortName() . ').');
        }

        $statusCode = $response->getStatusCode();
        $responseContent = (string)$response->getBody();

        if ($statusCode >= 400) {
            return [
                'success' => false,
                'status_code' => $statusCode,
                // Acotado: este texto puede acabar en una respuesta pública del chat.
                'error' => mb_strcut($responseContent, 0, 1024)
            ];
        }

        $decodedResponse = json_decode($responseContent, true);
        $data = (json_last_error() === JSON_ERROR_NONE) ? $decodedResponse : $responseContent;

        $result = [
            'success' => true,
            'status_code' => $statusCode,
            'data' => $data
        ];
        if ($statusCode >= 300) {
            $result['location'] = $response->getHeaderLine('Location');
        }
        return $result;
    }
}
