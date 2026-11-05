<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;

class GoogleMapsNode implements NodeInterface
{
    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        return ['type' => 'google_maps', 'name' => 'Google Maps & Places', 'category' => 'Community', 'icon' => 'MapPin', 'is_start' => false,
            'description' => 'Geocoding v4, Routes v2 y Places New con máscaras de campos acotadas.',
            'help_text' => 'API Key en generic_bearer; habilita Geocoding API, Routes API y Places API (New), facturación y restricciones por API/IP del servidor. No utiliza Directions/Places legacy. Una petición por ejecución, sin reintentos. Coordenadas JSON {"latitude":14.6,"longitude":-90.5}; rutas requieren origin y destination. Routes devuelve distanceMeters en metros y duration en segundos Google, aunque units=IMPERIAL cambia la presentación solicitada. Places solicita ID, nombre, dirección y ubicación (puede activar SKU Pro); no teléfonos, reseñas ni fotos. Paginación de búsqueda: page_token con la misma consulta/idioma/limit. data.items para búsquedas/geocodificación, data.routes para rutas, data.place para detalles. Cumple atribuciones y restricciones de almacenamiento de Google Maps. Sin triggers ni recursos cloud automáticos.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'label' => 'API Key Google Maps', 'required' => true],
                'operation' => ['type' => 'select', 'label' => 'Operación', 'required' => true, 'default' => 'geocode', 'options' => array_map(static fn ($v, $l) => ['value' => $v, 'label' => $l], ['geocode', 'reverse_geocode', 'route', 'search_places', 'place_details'], ['Geocodificar dirección', 'Geocodificación inversa', 'Ruta, distancia y duración', 'Buscar lugares', 'Detalle de lugar'])],
                'address' => ['type' => 'string', 'label' => 'Dirección (geocode)', 'required' => false],
                'location' => ['type' => 'textarea', 'label' => 'Coordenadas JSON (reverse_geocode)', 'required' => false],
                'origin' => ['type' => 'textarea', 'label' => 'Origen: coordenadas JSON (route)', 'required' => false],
                'destination' => ['type' => 'textarea', 'label' => 'Destino: coordenadas JSON (route)', 'required' => false],
                'travel_mode' => ['type' => 'select', 'label' => 'Transporte', 'required' => false, 'default' => 'DRIVE', 'options' => array_map(static fn ($v) => ['value' => $v, 'label' => $v], ['DRIVE', 'WALK', 'BICYCLE', 'TRANSIT'])],
                'units' => ['type' => 'select', 'label' => 'Unidades solicitadas', 'required' => false, 'default' => 'METRIC', 'options' => [['value' => 'METRIC', 'label' => 'Métricas'], ['value' => 'IMPERIAL', 'label' => 'Imperiales']]],
                'query' => ['type' => 'string', 'label' => 'Consulta de lugares', 'required' => false],
                'place_id' => ['type' => 'string', 'label' => 'ID de lugar (details)', 'required' => false],
                'language_code' => ['type' => 'string', 'label' => 'Idioma BCP-47 (opcional)', 'required' => false],
                'page_token' => ['type' => 'string', 'label' => 'Cursor Places (opcional)', 'required' => false],
                'limit' => ['type' => 'number', 'label' => 'Lugares por página (1–20)', 'required' => false, 'default' => 10]
            ]];
    }

    private function text(array $p, string $key, int $max = 2048): string
    {
        $value = $p[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new \InvalidArgumentException('Google Maps: campo inválido o ausente: ' . $key);
        }
        return $value;
    }

    private function coordinates(mixed $value): array
    {
        try {
            $point = is_string($value) ? json_decode($value, true, 8, JSON_THROW_ON_ERROR) : (is_object($value) ? (array)$value : $value);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Google Maps: coordenadas JSON inválidas.');
        }
        if (!is_array($point) || count($point) !== 2 || !isset($point['latitude'], $point['longitude'])
            || (!is_int($point['latitude']) && !is_float($point['latitude'])) || (!is_int($point['longitude']) && !is_float($point['longitude']))
            || !is_finite((float)$point['latitude']) || !is_finite((float)$point['longitude']) || abs($point['latitude']) > 90 || abs($point['longitude']) > 180) {
            throw new \InvalidArgumentException('Google Maps: requiere latitude [-90,90] y longitude [-180,180] numéricas.');
        }
        return $point;
    }

    private function request(array $p): array
    {
        $op = $p['operation'] ?? 'geocode';
        $method = 'GET';
        $options = ['headers' => ['X-Goog-FieldMask' => 'results.placeId,results.formattedAddress,results.location']];
        $language = $p['language_code'] ?? '';
        if (!is_string($language) || strlen($language) > 64 || ($language !== '' && !preg_match('/^[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{1,8})*$/D', $language))) {
            throw new \InvalidArgumentException('Google Maps: código de idioma inválido.');
        }
        if ($op === 'geocode') {
            $url = 'https://geocode.googleapis.com/v4/geocode/address/' . rawurlencode($this->text($p, 'address', 1024));
        } elseif ($op === 'reverse_geocode') {
            $point = $this->coordinates($p['location'] ?? null);
            $url = 'https://geocode.googleapis.com/v4/geocode/location';
            $options['query'] = ['location.latitude' => $point['latitude'], 'location.longitude' => $point['longitude']];
        } elseif ($op === 'route') {
            $mode = $p['travel_mode'] ?? 'DRIVE';
            $units = $p['units'] ?? 'METRIC';
            if (!in_array($mode, ['DRIVE', 'WALK', 'BICYCLE', 'TRANSIT'], true) || !in_array($units, ['METRIC', 'IMPERIAL'], true)) {
                throw new \InvalidArgumentException('Google Maps: modo de transporte o unidades inválidas.');
            }
            $method = 'POST';
            $url = 'https://routes.googleapis.com/directions/v2:computeRoutes';
            $options['headers']['X-Goog-FieldMask'] = 'routes.distanceMeters,routes.duration';
            $options['json'] = ['origin' => ['location' => ['latLng' => $this->coordinates($p['origin'] ?? null)]],
                'destination' => ['location' => ['latLng' => $this->coordinates($p['destination'] ?? null)]], 'travelMode' => $mode, 'units' => $units];
        } elseif ($op === 'search_places') {
            $method = 'POST';
            $url = 'https://places.googleapis.com/v1/places:searchText';
            $options['headers']['X-Goog-FieldMask'] = 'places.id,places.displayName,places.formattedAddress,places.location,nextPageToken';
            $limitValue = $p['limit'] ?? 10;
            $limit = (is_int($limitValue) || is_string($limitValue)) ? filter_var($limitValue, FILTER_VALIDATE_INT) : false;
            if ($limit === false || $limit < 1 || $limit > 20) {
                throw new \InvalidArgumentException('Google Maps: límite Places entre 1 y 20.');
            }
            $options['json'] = ['textQuery' => $this->text($p, 'query', 1024), 'pageSize' => $limit];
            if (isset($p['page_token']) && $p['page_token'] !== '') {
                $options['json']['pageToken'] = $this->text($p, 'page_token', 8192);
            }
        } elseif ($op === 'place_details') {
            $id = $this->text($p, 'place_id', 512);
            if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $id)) {
                throw new \InvalidArgumentException('Google Maps: ID de lugar inválido.');
            }
            $url = 'https://places.googleapis.com/v1/places/' . rawurlencode($id);
            $options['headers']['X-Goog-FieldMask'] = 'id,displayName,formattedAddress,location';
        } else {
            throw new \InvalidArgumentException('Google Maps: operación inválida.');
        }
        if ($language !== '') {
            if ($method === 'POST') {
                $options['json']['languageCode'] = $language;
            } else {
                $options['query']['languageCode'] = $language;
            }
        }
        return [$method, $url, $options, $op];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->text($parameters, 'credential_id', 255);
            $this->request($parameters);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    private function place(array $item, bool $geocode): array
    {
        $idKey = $geocode ? 'placeId' : 'id';
        if (!is_string($item[$idKey] ?? null) || $item[$idKey] === '' || strlen($item[$idKey]) > 512) {
            throw new \RuntimeException('Google Maps: identificador remoto inválido.');
        }
        $out = [$idKey => $item[$idKey]];
        if (isset($item['formattedAddress'])) {
            if (!is_string($item['formattedAddress']) || strlen($item['formattedAddress']) > 8192) {
                throw new \RuntimeException('Google Maps: dirección remota inválida.');
            }
            $out['formattedAddress'] = $item['formattedAddress'];
        }
        if (isset($item['location'])) {
            if (!is_array($item['location'])) {
                throw new \RuntimeException('Google Maps: ubicación remota inválida.');
            }
            $out['location'] = $this->coordinates(array_intersect_key($item['location'], array_flip(['latitude', 'longitude'])));
        }
        if (!$geocode && isset($item['displayName'])) {
            if (!is_array($item['displayName']) || !is_string($item['displayName']['text'] ?? null) || strlen($item['displayName']['text']) > 8192
                || (isset($item['displayName']['languageCode']) && !is_string($item['displayName']['languageCode']))) {
                throw new \RuntimeException('Google Maps: nombre remoto inválido.');
            }
            $out['displayName'] = array_intersect_key($item['displayName'], array_flip(['text', 'languageCode']));
        }
        return $out;
    }

    public function execute(array $parameters, array $context): array
    {
        $id = $this->text($parameters, 'credential_id', 255);
        [$method, $url, $options, $op] = $this->request($parameters);
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Google Maps: no se pudo recuperar la credencial; no se contactó Google.');
        }
        $key = ($credential['type'] ?? '') === 'generic_bearer' ? $credential['data']['api_key'] ?? null : null;
        if (!is_string($key) || $key === '' || strlen($key) > 512 || preg_match('/[\x00-\x20\x7f]/', $key)) {
            throw new \InvalidArgumentException('Google Maps: selecciona generic_bearer con API Key válida sin prefijo.');
        }
        $options['headers']['X-Goog-Api-Key'] = $key;
        $options += ['timeout' => 25, 'connect_timeout' => 8, 'read_timeout' => 25, 'http_errors' => false, 'allow_redirects' => false, 'verify' => true, 'stream' => true];
        try {
            $response = ($this->client ?? new Client())->request($method, $url, $options);
            $stream = $response->getBody();
            $raw = '';
            try {
                while (!$stream->eof() && strlen($raw) <= 2097152) {
                    $part = $stream->read(min(65536, 2097153 - strlen($raw)));
                    if ($part === '' && !$stream->eof()) {
                        throw new \RuntimeException();
                    }
                    $raw .= $part;
                }
            } finally {
                $stream->close();
            }
            if (strlen($raw) > 2097152) {
                throw new \RuntimeException();
            }
            $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Google Maps: fallo de transporte, tamaño o JSON. No se reintenta ni se expone la respuesta remota.');
        }
        if ($response->getStatusCode() !== 200 || !is_array($body) || isset($body['error']) || isset($body['error_message'])
            || (isset($body['status']) && !in_array($body['status'], ['OK', 'ZERO_RESULTS'], true))) {
            throw new \RuntimeException('Google Maps: servicio rechazó la solicitud; comprueba APIs habilitadas, facturación, cuota y restricciones de la API Key.');
        }
        $field = match ($op) { 'route' => 'routes', 'search_places' => 'places', 'place_details' => null, default => 'results' };
        if ($field === null) {
            if (!is_string($body['id'] ?? null) || $body['id'] === '') {
                throw new \RuntimeException('Google Maps: detalle de lugar inválido.');
            }
            $data = ['place' => $this->place($body, false)];
        } else {
            $items = $body[$field] ?? [];
            if (!is_array($items) || !array_is_list($items) || count($items) > 100) {
                throw new \RuntimeException('Google Maps: colección remota inválida.');
            }
            foreach ($items as &$item) {
                if (!is_array($item)) {
                    throw new \RuntimeException('Google Maps: elemento remoto inválido.');
                }
                if ($op === 'route') {
                    $distance = $item['distanceMeters'] ?? 0;
                    $duration = $item['duration'] ?? '0s';
                    if ((!isset($item['distanceMeters']) && !isset($item['duration'])) || !is_int($distance) || $distance < 0 || !is_string($duration) || !preg_match('/^[0-9]{1,12}(?:\.[0-9]{1,9})?s$/D', $duration)) {
                        throw new \RuntimeException('Google Maps: distancia o duración remota inválida.');
                    }
                    $item = ['distanceMeters' => $distance, 'duration' => $duration];
                } else {
                    $item = $this->place($item, $op !== 'search_places');
                }
            }
            unset($item);
            $data = [$op === 'route' ? 'routes' : 'items' => $items];
            $data['found'] = $items !== [];
            if ($op === 'search_places') {
                $token = $body['nextPageToken'] ?? null;
                if ($token !== null && (!is_string($token) || strlen($token) > 8192)) {
                    throw new \RuntimeException('Google Maps: cursor inválido.');
                }
                $data['next_page_token'] = $token;
            }
        }
        array_walk_recursive($data, static function (&$value) use ($key): void {
            if (is_string($value)) {
                $value = str_replace($key, '[redactado]', $value);
            }
        });
        return ['success' => true, 'data' => $data];
    }
}
