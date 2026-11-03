<?php

namespace P8P\Core\TriggerProviders;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use P8P\Core\TriggerRetryException;
use P8P\Interfaces\TriggerProviderInterface;

/** Calendar reminders scheduled from persistent due-time windows; no remote subscriptions. */
class CalendarPollingProviders implements TriggerProviderInterface
{
    public function __construct(private ?ClientInterface $client = null)
    {
    }

    public function definitions(): array
    {
        $field = static fn (string $label, bool $required = true): array => ['label' => $label, 'type' => 'string', 'required' => $required];
        $common = [
            'event' => 'calendar.event.upcoming', 'transport' => 'polling',
            'secret_fields' => ['access_token' => $field('Access token OAuth vigente, sin Bearer')],
            'help' => 'Recordatorio por ventana persistente: inicio menos lead_minutes. Primera lectura silenciosa; una página de 100 por turno. Omite todo el día, cancelados y eventos ya comenzados. Cron de un minuto y paginación pueden retrasar avisos; no garantiza entrega en un segundo exacto. Ver docs/triggers-calendar.md.',
        ];
        return [
            'microsoft_graph_event_upcoming' => [
                'name' => 'Microsoft Graph: evento próximo', 'node_type' => 'microsoft_graph',
                'config_fields' => ['lead_minutes' => $field('Anticipación en minutos (1–10080)'), 'token_mode' => $field('delegated o application', false), 'user_id' => $field('Usuario (obligatorio con application)', false), 'calendar_id' => $field('ID de calendario (vacío = predeterminado)', false)],
                'docs' => ['https://learn.microsoft.com/en-us/graph/api/calendar-list-calendarview?view=graph-rest-1.0'],
            ] + $common,
            'google_calendar_event_upcoming' => [
                'name' => 'Google Calendar: evento próximo', 'node_type' => 'google_calendar',
                'config_fields' => ['lead_minutes' => $field('Anticipación en minutos (1–10080)'), 'calendar_id' => $field('ID del calendario (primary permitido)')],
                'docs' => ['https://developers.google.com/workspace/calendar/api/v3/reference/events/list'],
            ] + $common,
        ];
    }

    public function verify(string $key, array $config, array $secrets, array $request, int $now): array
    {
        throw new \LogicException('Los eventos próximos se programan por polling.');
    }

    public function poll(string $key, array $config, array $secrets, array $state, int $now): array
    {
        if (!isset($this->definitions()[$key])) {
            throw new \InvalidArgumentException('Recordatorio de calendario desconocido.');
        }
        $leadValue = $config['lead_minutes'] ?? null;
        if ((!is_string($leadValue) && !is_int($leadValue)) || !preg_match('/^[1-9][0-9]{0,4}$/D', (string) $leadValue) || (int) $leadValue > 10080) {
            throw new \InvalidArgumentException('lead_minutes debe ser un entero entre 1 y 10080.');
        }
        $lead = (int) $leadValue * 60;
        $token = $secrets['access_token'] ?? $secrets['api_key'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 16384 || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new \InvalidArgumentException('Se requiere access_token OAuth válido.');
        }
        $graph = $key === 'microsoft_graph_event_upcoming';
        if ($graph) {
            $mode = $config['token_mode'] ?? 'delegated';
            if (!in_array($mode, ['delegated', 'application'], true)) {
                throw new \InvalidArgumentException('token_mode inválido.');
            }
            if ($mode === 'application' && (!isset($config['user_id']) || $config['user_id'] === '')) {
                throw new \InvalidArgumentException('Application requiere user_id explícito.');
            }
            $owner = !empty($config['user_id']) ? 'users/' . $this->segment($config['user_id']) : 'me';
            $calendar = !empty($config['calendar_id']) ? 'calendars/' . $this->segment($config['calendar_id']) : 'calendar';
            $path = '/v1.0/' . $owner . '/' . $calendar . '/calendarView';
            $base = 'https://graph.microsoft.com' . $path;
        } else {
            $path = '';
            $base = 'https://www.googleapis.com/calendar/v3/calendars/' . $this->segment($config['calendar_id'] ?? null) . '/events';
        }
        $boundConfig = $config;
        ksort($boundConfig);
        $binding = hash('sha256', $key . json_encode($boundConfig, JSON_THROW_ON_ERROR) . hash('sha256', $token));
        if (isset($state['binding']) && !hash_equals($state['binding'], $binding)) {
            throw new \InvalidArgumentException('Configuración o token cambiados; reactiva para una nueva línea base.');
        }
        $state['binding'] = $binding;
        $baseline = !isset($state['watermark']);
        if (!$baseline && (!is_int($state['watermark']) || $state['watermark'] < 0)) {
            throw new \InvalidArgumentException('Marca de calendario inválida.');
        }
        if (!$baseline && !isset($state['window']) && $now <= $state['watermark']) {
            return ['events' => [], 'state' => $state, 'has_more' => false];
        }
        // Initial one-second future read checks access without replaying historical due events.
        $window = $state['window'] ?? ['from' => $baseline ? $now : max(0, $state['watermark'] - 2), 'to' => $baseline ? $now + 1 : $now];
        if (!is_array($window) || !is_int($window['from'] ?? null) || !is_int($window['to'] ?? null) || $window['from'] < 0 || $window['to'] <= $window['from']) {
            throw new \InvalidArgumentException('Ventana persistida de calendario inválida.');
        }
        $start = $this->iso($window['from'] + $lead);
        $end = $this->iso($window['to'] + $lead);
        $query = $graph ? [
            'startDateTime' => $start, 'endDateTime' => $end, '$top' => 100, '$orderby' => 'start/dateTime',
            '$select' => 'id,subject,start,end,isAllDay,isCancelled,type,seriesMasterId',
        ] : [
            'timeMin' => $start, 'timeMax' => $end, 'timeZone' => 'UTC',
            'singleEvents' => 'true', 'showDeleted' => 'false', 'orderBy' => 'startTime', 'maxResults' => 100,
            'fields' => 'nextPageToken,items(id,summary,status,start,end,recurringEventId,originalStartTime)',
        ];
        $next = $state['next'] ?? null;
        $url = $base;
        if ($next !== null) {
            if ($graph) {
                $url = $this->graphLink($next, $path);
                $query = [];
            } else {
                $query['pageToken'] = $this->cursor($next);
            }
        }
        $body = $this->get($url, $query, $token, $graph, $now);
        $items = $body[$graph ? 'value' : 'items'] ?? [];
        if (!is_array($items) || !array_is_list($items) || count($items) > 100) {
            throw new \RuntimeException('Página de calendario inválida o superior a 100.');
        }
        $events = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('Evento de calendario inválido.');
            }
            if (($item['isAllDay'] ?? false) === true || ($item['isCancelled'] ?? false) === true || ($item['status'] ?? '') === 'cancelled' || isset($item['start']['date'])) {
                continue;
            }
            $starts = $this->instant($item['start'] ?? null, $graph);
            $due = $starts - $lead;
            // APIs return overlapping events too. Admit only start instants in our half-open window.
            if ($baseline || $due < $window['from'] || $due >= $window['to'] || $starts <= $now) {
                continue;
            }
            $id = $item['id'] ?? null;
            if (!is_string($id) || $id === '' || strlen($id) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $id)) {
                throw new \RuntimeException('Identificador de evento inválido.');
            }
            $data = [
                'id' => $id, 'summary' => $item[$graph ? 'subject' : 'summary'] ?? '',
                'start' => $this->iso($starts), 'due_at' => $this->iso($due),
                'lead_minutes' => (int) $leadValue, 'calendar_id' => $config['calendar_id'] ?? 'default',
            ];
            if (!is_string($data['summary'])) {
                throw new \RuntimeException('Resumen de evento inválido.');
            }
            if (isset($item['end'])) {
                $data['end'] = $this->iso($this->instant($item['end'], $graph));
            }
            $series = $item[$graph ? 'seriesMasterId' : 'recurringEventId'] ?? null;
            if (is_string($series)) {
                $data['series_id'] = $series;
            }
            foreach ($data as &$value) {
                if (is_string($value)) {
                    $value = str_replace($token, '[redactado]', $value);
                }
            }
            unset($value);
            $events[] = ['id' => hash('sha256', $id . '|' . $this->iso($starts)), 'type' => 'calendar.event.upcoming', 'occurred_at' => $this->iso($due), 'data' => $data];
        }
        $continuation = $body[$graph ? '@odata.nextLink' : 'nextPageToken'] ?? null;
        if ($continuation !== null && $continuation !== '') {
            $continuation = $graph ? $this->graphLink($continuation, $path) : $this->cursor($continuation);
            if ($continuation === $next) {
                throw new \RuntimeException('La página de calendario no avanzó.');
            }
        } else {
            $continuation = null;
        }
        if (!$baseline && $continuation !== null) {
            $state['window'] = $window;
            $state['next'] = $continuation;
        } else {
            $state['watermark'] = $baseline ? $now : $window['to'];
            unset($state['window'], $state['next']);
        }
        return ['events' => $events, 'state' => $state, 'has_more' => !$baseline && $continuation !== null];
    }

    private function get(string $url, array $query, string $token, bool $graph, int $now): array
    {
        try {
            $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
            if ($graph) {
                $headers['Prefer'] = 'outlook.timezone="UTC", odata.maxpagesize=100, IdType="ImmutableId"';
            }
            $response = ($this->client ??= new Client())->request('GET', $url, ['query' => $query ?: null, 'headers' => $headers,
                'verify' => true, 'allow_redirects' => false, 'connect_timeout' => 5, 'timeout' => 20, 'http_errors' => false, 'stream' => true]);
            $raw = '';
            $stream = $response->getBody();
            while (!$stream->eof() && strlen($raw) <= 4194304) {
                $chunk = $stream->read(min(8192, 4194305 - strlen($raw)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new \RuntimeException('Lectura incompleta.');
                }
                $raw .= $chunk;
            }
            $stream->close();
        } catch (\Throwable $e) {
            throw new TriggerRetryException(60);
        }
        $status = $response->getStatusCode();
        if ($status === 429 || $status === 408 || $status >= 500) {
            $header = $response->getHeaderLine('Retry-After');
            $seconds = ctype_digit($header) ? (int) $header : (($date = strtotime($header)) !== false ? $date - $now : 60);
            throw new TriggerRetryException(max(1, min(86400, $seconds)));
        }
        if (strlen($raw) > 4194304) {
            throw new \RuntimeException('Respuesta de calendario superior a 4 MiB.');
        }
        $data = json_decode($raw, true);
        if ($status === 403 && is_array($data)) {
            foreach (($data['error']['errors'] ?? []) as $error) {
                if (in_array($error['reason'] ?? '', ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded'], true)) {
                    throw new TriggerRetryException(300);
                }
            }
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Lectura de calendario rechazada (HTTP ' . $status . '); revisa token y permisos.');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new \RuntimeException('JSON de calendario inválido.');
        }
        return $data;
    }

    private function graphLink(mixed $url, string $path): string
    {
        if (!is_string($url) || strlen($url) > 32768 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new \RuntimeException('Cursor Graph inválido.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'graph.microsoft.com'
            || ($parts['path'] ?? '') !== $path || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || !isset($parts['query'])) {
            throw new \RuntimeException('Cursor Graph fuera del calendarView autorizado.');
        }
        parse_str($parts['query'], $parameters);
        foreach (array_keys($parameters) as $name) {
            if (!in_array($name, ['startDateTime', 'endDateTime', '$select', '$orderby', '$top', '$skip', '$skiptoken'], true)) {
                throw new \RuntimeException('Parámetro de continuación Graph no autorizado.');
            }
        }
        return $url;
    }

    private function segment(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $value) || in_array($value, ['.', '..'], true)) {
            throw new \InvalidArgumentException('Identificador de calendario o usuario inválido.');
        }
        return rawurlencode($value);
    }

    private function cursor(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 32768 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new \RuntimeException('Cursor de calendario inválido.');
        }
        return $value;
    }

    private function instant(mixed $date, bool $graph): int
    {
        if (!is_array($date) || !is_string($date['dateTime'] ?? null)) {
            throw new \RuntimeException('El calendario devolvió una fecha de inicio inválida.');
        }
        $value = $date['dateTime'];
        if ($graph && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?$/D', $value)) {
            if (!in_array($date['timeZone'] ?? '', ['UTC', 'Etc/UTC'], true)) {
                throw new \RuntimeException('Graph no respetó la zona UTC solicitada.');
            }
            $value .= 'Z';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) || ($time = strtotime($value)) === false) {
            throw new \RuntimeException('Instante de calendario inválido.');
        }
        return $time;
    }

    private function iso(int $time): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $time);
    }
}
