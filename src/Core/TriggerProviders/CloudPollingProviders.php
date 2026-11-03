<?php

namespace P8P\Core\TriggerProviders;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use P8P\Core\TriggerRetryException;
use P8P\Interfaces\TriggerProviderInterface;

/** Bounded, read-only adapters. State is committed together with events by TriggerManager. */
class CloudPollingProviders implements TriggerProviderInterface
{
    private const BODY_LIMIT = 4194304;
    private ?ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client;
    }

    public function definitions(): array
    {
        $field = static fn (string $label, bool $required = true): array => ['label' => $label, 'type' => 'string', 'required' => $required];
        $specs = [
            'x_mentions' => ['X: menciones nuevas', 'x_twitter', 'post.mentioned', ['user_id' => $field('ID numérico del usuario')], 'https://docs.x.com/x-api/users/get-mentions'],
            'x_hashtag' => ['X: publicaciones con hashtag', 'x_twitter', 'post.hashtag', ['hashtag' => $field('Hashtag único (sin #)')], 'https://docs.x.com/x-api/posts/search-recent-posts'],
            'youtube_comments' => ['YouTube: comentario principal nuevo', 'youtube', 'comment.published', ['video_id' => $field('ID del video')], 'https://developers.google.com/youtube/v3/docs/commentThreads/list'],
            'google_forms_new_response' => ['Google Forms: respuesta nueva', 'google_forms', 'response.created', ['form_id' => $field('ID del formulario editable')], 'https://developers.google.com/forms/api/reference/rest/v1/forms.responses/list'],
            'google_forms_response_changed' => ['Google Forms: respuesta reenviada', 'google_forms', 'response.updated', ['form_id' => $field('ID del formulario editable')], 'https://developers.google.com/forms/api/reference/rest/v1/forms.responses'],
            'google_drive_changes' => ['Google Drive: cambios de archivos', 'google_drive', 'file.changed', [], 'https://developers.google.com/drive/api/reference/rest/v3/changes/list'],
            'google_calendar_changes' => ['Google Calendar: cambios de eventos', 'google_calendar', 'calendar.event.changed', ['calendar_id' => $field('ID del calendario (primary permitido)')], 'https://developers.google.com/calendar/api/guides/sync'],
            'microsoft_graph_new_mail' => ['Microsoft Graph: correo recibido', 'microsoft_graph', 'mail.received', ['mail_folder_id' => $field('Carpeta (inbox permitido)'), 'user_id' => $field('ID de usuario (obligatorio con application)', false), 'token_mode' => $field('delegated o application', false)], 'https://learn.microsoft.com/en-us/graph/api/message-delta?view=graph-rest-1.0'],
            'onedrive_changes' => ['OneDrive: cambios de elementos', 'onedrive', 'drive.item.changed', ['drive_id' => $field('ID de unidad (obligatorio con application)', false), 'token_mode' => $field('delegated o application', false)], 'https://learn.microsoft.com/en-us/graph/api/driveitem-delta?view=graph-rest-1.0'],
            'google_sheets_new_rows' => ['Google Sheets: fila antes vacía con valores', 'google_sheets', 'sheet.row.created', ['spreadsheet_id' => $field('ID del spreadsheet'), 'range' => $field('Rango A1 finito (máximo 100 filas y 100 columnas)')], 'https://developers.google.com/sheets/api/reference/rest/v4/spreadsheets.values/get'],
            'google_sheets_row_changes' => ['Google Sheets: fila modificada o vaciada', 'google_sheets', 'sheet.row.changed', ['spreadsheet_id' => $field('ID del spreadsheet'), 'range' => $field('Rango A1 finito (máximo 100 filas y 100 columnas)')], 'https://developers.google.com/sheets/api/reference/rest/v4/spreadsheets.values/get'],
            'google_docs_changed' => ['Google Docs: versión de archivo modificada (Drive)', 'google_docs', 'document.file.changed', ['document_id' => $field('ID del documento')], 'https://developers.google.com/drive/api/reference/rest/v3/files'],
        ];
        $definitions = [];
        foreach ($specs as $key => [$name, $nodeType, $event, $fields, $docs]) {
            $definitions[$key] = [
                'name' => $name, 'node_type' => $nodeType, 'event' => $event, 'transport' => 'polling',
                'config_fields' => $fields, 'secret_fields' => ['access_token' => $field('Access token OAuth (sin Bearer)')],
                'help' => 'Lecturas de una página (máximo 100) por turno, sin histórico inicial. Token OAuth vigente; no renueva OAuth. Consulta docs/triggers-cloud.md para permisos, baseline y límites del evento exacto. No crea suscripciones remotas.',
                'docs' => [$docs],
            ];
        }
        return $definitions;
    }

    public function verify(string $key, array $config, array $secrets, array $request, int $now): array
    {
        throw new \LogicException('Este proveedor utiliza polling; no admite recepción pública.');
    }

    public function poll(string $key, array $config, array $secrets, array $state, int $now): array
    {
        if (!isset($this->definitions()[$key])) {
            throw new \InvalidArgumentException('Trigger cloud desconocido.');
        }
        foreach ($this->definitions()[$key]['config_fields'] as $name => $field) {
            if ($field['required']) {
                $this->text($config, $name);
            }
        }
        $token = $secrets['access_token'] ?? $secrets['api_key'] ?? null;
        if (!is_string($token) || trim($token) === '' || strlen($token) > 16384 || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new \InvalidArgumentException('Se requiere un access_token válido sin prefijo Bearer.');
        }
        // Credentials/configuration cannot be swapped under a persisted cursor.
        $binding = hash('sha256', $key . json_encode($config, JSON_THROW_ON_ERROR) . hash('sha256', $token));
        if (isset($state['binding']) && !hash_equals($state['binding'], $binding)) {
            throw new \InvalidArgumentException('Configuración o credencial cambiada; desactiva y reactiva el trigger para establecer una nueva línea base.');
        }
        $state['binding'] = $binding;
        if (!isset($state['activated_at'])) {
            $state['activated_at'] = $this->iso($now);
        }
        $this->timestamp($state['activated_at']);
        $result = match (true) {
            str_starts_with($key, 'x_') => $this->x($key, $config, $token, $state, $now),
            $key === 'youtube_comments' => $this->youtube($config, $token, $state, $now),
            str_starts_with($key, 'google_forms_') => $this->forms($key, $config, $token, $state, $now),
            $key === 'google_drive_changes' => $this->drive($token, $state, $now),
            $key === 'google_calendar_changes' => $this->calendar($config, $token, $state, $now),
            $key === 'microsoft_graph_new_mail', $key === 'onedrive_changes' => $this->graph($key, $config, $token, $state, $now),
            str_starts_with($key, 'google_sheets_') => $this->sheets($key, $config, $token, $state, $now),
            $key === 'google_docs_changed' => $this->docs($config, $token, $state, $now),
        };
        // Never expose configured bearer strings echoed by an untrusted upstream field.
        foreach ($result['events'] as &$event) {
            $event['data'] = $this->redact($event['data'], $token);
        }
        unset($event);
        return $result;
    }

    private function x(string $key, array $config, string $token, array $state, int $now): array
    {
        if ($key === 'x_mentions') {
            $id = $this->text($config, 'user_id');
            if (!preg_match('/^[0-9]{1,25}$/D', $id)) {
                throw new \InvalidArgumentException('user_id debe ser numérico.');
            }
            $url = 'https://api.x.com/2/users/' . $id . '/mentions';
        } else {
            $tag = $this->text($config, 'hashtag');
            if (!preg_match('/^[\pL\pN_]{1,100}$/uD', $tag)) {
                throw new \InvalidArgumentException('hashtag debe ser una única palabra sin #.');
            }
            $url = 'https://api.x.com/2/tweets/search/recent';
        }
        // Snowflake lower bound excludes history without an unsafe "fetch latest then advance" race.
        $state['since_id'] ??= (string) (max(0, $this->timestamp($state['activated_at']) * 1000 - 1288834974657) << 22);
        $query = ['max_results' => 100, 'since_id' => $state['since_id'], 'tweet.fields' => 'id,text,author_id,created_at'];
        if ($key === 'x_hashtag') {
            $query['query'] = '#' . $tag;
            $query['sort_order'] = 'recency';
        }
        if (isset($state['page'])) {
            $query[$key === 'x_mentions' ? 'pagination_token' : 'next_token'] = $state['page'];
        }
        $body = $this->get($url, $token, $query, $now);
        $events = [];
        $maximum = $state['pending_max'] ?? $state['since_id'];
        foreach ($this->items($body, 'data') as $post) {
            $id = $this->text($post, 'id');
            if (!preg_match('/^[0-9]{1,25}$/D', $id)) {
                throw new \RuntimeException('La API devolvió un identificador inválido.');
            }
            if ($this->numberCompare($id, $state['since_id']) <= 0) {
                continue;
            }
            $maximum = $this->numberCompare($id, $maximum) > 0 ? $id : $maximum;
            $events[] = $this->event($id, $this->definitions()[$key]['event'], $this->pick($post, ['id', 'text', 'author_id', 'created_at']), $post['created_at'] ?? null);
        }
        $next = $this->optionalToken($body['meta']['next_token'] ?? null);
        if ($next !== null) {
            $this->progress($state['page'] ?? null, $next);
            $state['page'] = $next;
            $state['pending_max'] = $maximum;
        } else {
            $state['since_id'] = $maximum;
            unset($state['page'], $state['pending_max']);
        }
        return $this->result($events, $state, $next !== null);
    }

    private function youtube(array $config, string $token, array $state, int $now): array
    {
        $state['watermark'] ??= $state['activated_at'];
        $state['cycle_at'] ??= $this->iso($now);
        $query = ['part' => 'snippet', 'videoId' => $this->id($config, 'video_id'), 'maxResults' => 100, 'order' => 'time', 'textFormat' => 'plainText'];
        if (isset($state['page'])) {
            $query['pageToken'] = $state['page'];
        }
        $body = $this->get('https://www.googleapis.com/youtube/v3/commentThreads', $token, $query, $now);
        $events = [];
        $reachedBoundary = false;
        foreach ($this->items($body, 'items') as $thread) {
            $comment = $thread['snippet']['topLevelComment'] ?? [];
            $snippet = $comment['snippet'] ?? [];
            $published = $snippet['publishedAt'] ?? '';
            if ($this->timestamp($published) < $this->timestamp($state['watermark'])) {
                $reachedBoundary = true;
                continue;
            }
            $id = $this->text($comment, 'id');
            $events[] = $this->event($id, 'comment.published', ['id' => $id, 'video_id' => $config['video_id']] + $this->pick($snippet, ['textDisplay', 'authorDisplayName', 'publishedAt']), $published);
        }
        $next = $reachedBoundary ? null : $this->optionalToken($body['nextPageToken'] ?? null);
        if ($next !== null) {
            $this->progress($state['page'] ?? null, $next);
            $state['page'] = $next;
        } else {
            $state['watermark'] = $this->iso(max($this->timestamp($state['activated_at']), $this->timestamp($state['cycle_at']) - 120));
            unset($state['page'], $state['cycle_at']);
        }
        return $this->result($events, $state, $next !== null);
    }

    private function forms(string $key, array $config, string $token, array $state, int $now): array
    {
        $state['watermark'] ??= $state['activated_at'];
        $state['cycle_at'] ??= $this->iso($now);
        $query = ['pageSize' => 100, 'filter' => 'timestamp >= ' . $state['watermark']];
        if (isset($state['page'])) {
            $query['pageToken'] = $state['page'];
        }
        $body = $this->get('https://forms.googleapis.com/v1/forms/' . $this->id($config, 'form_id') . '/responses', $token, $query, $now);
        $events = [];
        foreach ($this->items($body, 'responses') as $response) {
            $created = $response['createTime'] ?? '';
            $submitted = $response['lastSubmittedTime'] ?? $created;
            $createdAt = $this->timestamp($created);
            $submittedAt = $this->timestamp($submitted);
            $activation = $this->timestamp($state['activated_at']);
            $new = $key === 'google_forms_new_response';
            if (($new && $createdAt < $activation) || (!$new && ($submitted === $created || $submittedAt < $activation))) {
                continue;
            }
            $id = $this->text($response, 'responseId');
            $eventId = $new ? $id : $id . ':' . $submitted;
            $data = $this->pick($response, ['responseId', 'createTime', 'lastSubmittedTime', 'respondentEmail']);
            $data['form_id'] = $config['form_id'];
            // File answers deliberately expose no upload or signed-download URLs.
            $data['answers'] = [];
            foreach (($response['answers'] ?? []) as $questionId => $answer) {
                if (is_array($answer)) {
                    $data['answers'][$questionId] = $this->pick($answer, ['questionId', 'textAnswers']);
                }
            }
            $events[] = $this->event($eventId, $this->definitions()[$key]['event'], $data, $new ? $created : $submitted);
        }
        $next = $this->optionalToken($body['nextPageToken'] ?? null);
        if ($next !== null) {
            $this->progress($state['page'] ?? null, $next);
            $state['page'] = $next;
        } else {
            $state['watermark'] = $this->iso(max($this->timestamp($state['activated_at']), $this->timestamp($state['cycle_at']) - 120));
            unset($state['page'], $state['cycle_at']);
        }
        return $this->result($events, $state, $next !== null);
    }

    private function drive(string $token, array $state, int $now): array
    {
        if (!isset($state['cursor'])) {
            $body = $this->get('https://www.googleapis.com/drive/v3/changes/startPageToken', $token, [], $now);
            $state['cursor'] = $this->text($body, 'startPageToken');
            $state['baseline_at'] = $this->iso($now);
            return $this->result([], $state, false);
        }
        $body = $this->get('https://www.googleapis.com/drive/v3/changes', $token, [
            'pageToken' => $state['cursor'], 'pageSize' => 100, 'spaces' => 'drive', 'includeRemoved' => 'true',
            'fields' => 'nextPageToken,newStartPageToken,changes(fileId,time,removed,changeType,file(id,name,mimeType,modifiedTime,version,trashed))',
        ], $now);
        $events = [];
        foreach ($this->items($body, 'changes') as $change) {
            if (($change['changeType'] ?? 'file') !== 'file') {
                continue;
            }
            $id = $this->text($change, 'fileId');
            $data = $this->pick($change, ['fileId', 'time', 'removed']);
            $data['file'] = $this->pick($change['file'] ?? [], ['id', 'name', 'mimeType', 'modifiedTime', 'version', 'trashed']);
            $version = $change['file']['version'] ?? ($change['time'] ?? hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)));
            $events[] = $this->event($id . ':' . $version . ':' . (int) ($change['removed'] ?? false), 'file.changed', $data, $change['time'] ?? null);
        }
        $next = $this->optionalToken($body['nextPageToken'] ?? null);
        $cursor = $next ?? $this->optionalToken($body['newStartPageToken'] ?? null);
        if ($cursor === null) {
            throw new \RuntimeException('Drive no devolvió cursor de continuación.');
        }
        if ($next !== null) {
            $this->progress($state['cursor'], $next);
        }
        $state['cursor'] = $cursor;
        return $this->result($events, $state, $next !== null);
    }

    private function calendar(array $config, string $token, array $state, int $now): array
    {
        $query = ['maxResults' => 100, 'showDeleted' => 'true', 'singleEvents' => 'false'];
        if (isset($state['sync'])) {
            $query['syncToken'] = $state['sync'];
        }
        if (isset($state['page'])) {
            $query['pageToken'] = $state['page'];
        }
        $body = $this->get('https://www.googleapis.com/calendar/v3/calendars/' . $this->id($config, 'calendar_id') . '/events', $token, $query, $now);
        $events = [];
        foreach ($this->items($body, 'items') as $item) {
            // Full initial traversal only emits modifications since activation; no historic events.
            if (!isset($state['sync']) && (!isset($item['updated']) || $this->timestamp($item['updated']) < $this->timestamp($state['activated_at']))) {
                continue;
            }
            $id = $this->text($item, 'id');
            $version = $item['etag'] ?? $item['updated'] ?? null;
            if (!is_string($version) || $version === '') {
                throw new \RuntimeException('Calendar no devolvió versión del evento.');
            }
            $events[] = $this->event($id . ':' . hash('sha256', $version), 'calendar.event.changed', $this->pick($item, ['id', 'summary', 'status', 'updated', 'start', 'end', 'recurringEventId', 'recurrence']), $item['updated'] ?? null);
        }
        $next = $this->optionalToken($body['nextPageToken'] ?? null);
        if ($next !== null) {
            $this->progress($state['page'] ?? null, $next);
            $state['page'] = $next;
        } else {
            $state['sync'] = $this->text($body, 'nextSyncToken');
            unset($state['page']);
        }
        return $this->result($events, $state, $next !== null);
    }

    private function graph(string $key, array $config, string $token, array $state, int $now): array
    {
        $mode = $config['token_mode'] ?? 'delegated';
        if (!in_array($mode, ['delegated', 'application'], true)) {
            throw new \InvalidArgumentException('token_mode debe ser delegated o application.');
        }
        $mail = $key === 'microsoft_graph_new_mail';
        if ($mail) {
            if ($mode === 'application' && !isset($config['user_id'])) {
                throw new \InvalidArgumentException('Un token application exige user_id explícito.');
            }
            $owner = isset($config['user_id']) && $config['user_id'] !== '' ? 'users/' . $this->id($config, 'user_id') : 'me';
            $path = '/v1.0/' . $owner . '/mailFolders/' . $this->id($config, 'mail_folder_id') . '/messages/delta';
            $query = ['changeType' => 'created', '$top' => 100, '$select' => 'id,subject,receivedDateTime,internetMessageId,from', '$filter' => 'receivedDateTime ge ' . $state['activated_at']];
        } else {
            if ($mode === 'application' && (!isset($config['drive_id']) || $config['drive_id'] === '')) {
                throw new \InvalidArgumentException('Un token application exige drive_id explícito.');
            }
            $owner = isset($config['drive_id']) && $config['drive_id'] !== '' ? 'drives/' . $this->id($config, 'drive_id') : 'me/drive';
            $path = '/v1.0/' . $owner . '/root/delta';
            $query = ['token' => 'latest'];
        }
        $initial = !isset($state['link']);
        $url = $initial ? 'https://graph.microsoft.com' . $path : $this->graphLink($state['link'], $path);
        $body = $this->get($url, $token, $initial ? $query : [], $now, ['Prefer' => 'odata.maxpagesize=100' . ($mail ? ', IdType="ImmutableId"' : '')]);
        $events = [];
        foreach ($this->items($body, 'value') as $item) {
            if (!$mail && empty($state['baseline_complete'])) {
                continue;
            }
            $id = $this->text($item, 'id');
            if ($mail) {
                if (isset($item['@removed']) || !isset($item['receivedDateTime']) || $this->timestamp($item['receivedDateTime']) < $this->timestamp($state['activated_at'])) {
                    continue;
                }
                $events[] = $this->event($id, 'mail.received', $this->pick($item, ['id', 'subject', 'receivedDateTime', 'internetMessageId', 'from']), $item['receivedDateTime']);
            } else {
                $data = $this->pick($item, ['id', 'name', 'size', 'eTag', 'cTag', 'createdDateTime', 'lastModifiedDateTime', 'deleted']);
                $version = $item['eTag'] ?? $item['cTag'] ?? $item['lastModifiedDateTime'] ?? null;
                if ($version === null && !isset($item['deleted'])) {
                    throw new \RuntimeException('Graph no devolvió versión del elemento.');
                }
                // Deleted entries may only contain id/deleted. Bind them to this delta checkpoint.
                $eventId = $id . ':' . hash('sha256', (string) ($version ?? $state['link']) . ':' . (int) isset($item['deleted']));
                $events[] = $this->event($eventId, 'drive.item.changed', $data, $item['lastModifiedDateTime'] ?? null);
            }
        }
        $next = $this->optionalToken($body['@odata.nextLink'] ?? null);
        $link = $next ?? $this->optionalToken($body['@odata.deltaLink'] ?? null);
        if ($link === null) {
            throw new \RuntimeException('Graph no devolvió deltaLink ni nextLink.');
        }
        $this->graphLink($link, $path);
        if ($next !== null) {
            $this->progress($state['link'] ?? null, $next);
        }
        $state['link'] = $link;
        if (!$mail && $next === null) {
            $state['baseline_complete'] = true;
            $state['baseline_at'] ??= $this->iso($now);
        }
        return $this->result($events, $state, $next !== null);
    }

    private function sheets(string $key, array $config, string $token, array $state, int $now): array
    {
        $range = $this->text($config, 'range');
        if (!preg_match('/^(?:[^!\r\n]+!)?([A-Z]{1,3})([1-9][0-9]{0,6}):([A-Z]{1,3})([1-9][0-9]{0,6})$/D', $range, $match)) {
            throw new \InvalidArgumentException('range debe ser A1 finito, como Hoja1!A1:D100.');
        }
        $column = static function (string $letters): int {
            $value = 0;
            foreach (str_split($letters) as $letter) {
                $value = $value * 26 + ord($letter) - 64;
            }
            return $value;
        };
        $count = (int) $match[4] - (int) $match[2] + 1;
        $columns = $column($match[3]) - $column($match[1]) + 1;
        if ($count < 1 || $count > 100 || $columns < 1 || $columns > 100) {
            throw new \InvalidArgumentException('range debe contener entre 1 y 100 filas y columnas.');
        }
        $body = $this->get('https://sheets.googleapis.com/v4/spreadsheets/' . $this->id($config, 'spreadsheet_id') . '/values/' . rawurlencode($range), $token, ['majorDimension' => 'ROWS', 'valueRenderOption' => 'UNFORMATTED_VALUE'], $now);
        $rows = $this->items($body, 'values');
        if (count($rows) > $count) {
            throw new \RuntimeException('Sheets devolvió más filas que el rango autorizado.');
        }
        $events = [];
        $generation = (int) ($state['generation'] ?? 0) + 1;
        $hashes = [];
        $nonempty = [];
        for ($i = 0; $i < $count; $i++) {
            $row = array_values($rows[$i] ?? []);
            if (count($row) > $columns || count(array_filter($row, static fn ($v) => !is_scalar($v) && $v !== null)) > 0) {
                throw new \RuntimeException('Sheets devolvió una fila inválida.');
            }
            // API omits trailing empty cells/rows. Canonicalize them before hashing.
            $row = array_pad($row, $columns, '');
            $row = array_map(static fn ($v) => $v === null ? '' : $v, $row);
            $hashes[$i] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
            $nonempty[$i] = count(array_filter($row, static fn ($v) => $v !== '')) > 0;
            if (!isset($state['hashes']) || ($state['hashes'][$i] ?? null) === $hashes[$i]) {
                continue;
            }
            if ($key === 'google_sheets_new_rows' && ((!empty($state['nonempty'][$i])) || !$nonempty[$i])) {
                continue;
            }
            $rowNumber = (int) $match[2] + $i;
            $events[] = $this->event($rowNumber . ':' . $generation . ':' . $hashes[$i], $this->definitions()[$key]['event'], ['spreadsheet_id' => $config['spreadsheet_id'], 'range' => $range, 'row_number' => $rowNumber, 'values' => $row, 'empty' => !$nonempty[$i]], $this->iso($now));
        }
        $state['hashes'] = $hashes;
        $state['nonempty'] = $nonempty;
        $state['generation'] = $generation;
        $state['baseline_at'] ??= $this->iso($now);
        return $this->result($events, $state, false);
    }

    private function docs(array $config, string $token, array $state, int $now): array
    {
        // Drive version is monotonic and avoids Docs revisionId's 24h/user/format caveats.
        $body = $this->get('https://www.googleapis.com/drive/v3/files/' . $this->id($config, 'document_id'), $token, ['fields' => 'id,name,mimeType,modifiedTime,version,trashed'], $now);
        if (($body['mimeType'] ?? null) !== 'application/vnd.google-apps.document') {
            throw new \InvalidArgumentException('document_id debe corresponder a un documento Google Docs.');
        }
        $version = $this->text($body, 'version');
        $events = [];
        if (isset($state['version']) && $state['version'] !== $version) {
            $events[] = $this->event($this->text($body, 'id') . ':' . $version, 'document.file.changed', $this->pick($body, ['id', 'name', 'mimeType', 'modifiedTime', 'version', 'trashed']), $body['modifiedTime'] ?? null);
        }
        $state['version'] = $version;
        $state['baseline_at'] ??= $this->iso($now);
        return $this->result($events, $state, false);
    }

    private function get(string $url, string $token, array $query, int $now, array $headers = []): array
    {
        try {
            $response = ($this->client ??= new Client())->request('GET', $url, [
                'query' => $query === [] ? null : $query,
                'headers' => $headers + ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
                'http_errors' => false, 'allow_redirects' => false, 'verify' => true,
                'connect_timeout' => 5, 'timeout' => 20, 'stream' => true,
            ]);
            $stream = $response->getBody();
            $raw = '';
            while (!$stream->eof() && strlen($raw) <= self::BODY_LIMIT) {
                $chunk = $stream->read(min(8192, self::BODY_LIMIT + 1 - strlen($raw)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new \RuntimeException('Lectura HTTP incompleta.');
                }
                $raw .= $chunk;
            }
            $stream->close();
        } catch (\Throwable $e) {
            // Exception text/previous may contain credentials or signed URLs: never propagate it.
            throw new TriggerRetryException(60);
        }
        $status = $response->getStatusCode();
        if ($status === 429 || $status === 408 || $status >= 500) {
            $retry = $response->getHeaderLine('Retry-After');
            $seconds = ctype_digit($retry) ? (int) $retry : (($time = strtotime($retry)) !== false ? $time - $now : 60);
            throw new TriggerRetryException(max(1, min(86400, $seconds)));
        }
        if (strlen($raw) > self::BODY_LIMIT) {
            throw new \RuntimeException('Respuesta de polling superior a 4 MiB; reduce datos en el proveedor.');
        }
        $body = json_decode($raw, true);
        if ($status === 403 && is_array($body)) {
            foreach (($body['error']['errors'] ?? []) as $error) {
                if (in_array($error['reason'] ?? '', ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded'], true)) {
                    throw new TriggerRetryException(300);
                }
            }
        }
        if ($status === 410) {
            throw new \RuntimeException('Cursor del proveedor expirado; desactiva y reactiva para crear una nueva línea base. El intervalo perdido no puede reconstruirse de forma segura.');
        }
        if ($status === 401 || $status === 403) {
            throw new \RuntimeException('Credencial de polling rechazada o permisos insuficientes (HTTP ' . $status . ').');
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('El proveedor rechazó la lectura de polling (HTTP ' . $status . ').');
        }
        if (!is_array($body) || array_is_list($body) && $body !== []) {
            throw new \RuntimeException('El proveedor devolvió JSON inválido.');
        }
        return $body;
    }

    private function graphLink(string $url, string $path): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'graph.microsoft.com'
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== $path || !isset($parts['query']) || strlen($url) > 32768 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new \RuntimeException('Graph devolvió un cursor fuera del recurso HTTPS autorizado.');
        }
        parse_str($parts['query'], $query);
        foreach (array_keys($query) as $name) {
            if (!in_array($name, ['$skiptoken', '$deltatoken', 'token', '$select', '$top', '$filter', 'changeType'], true)) {
                throw new \RuntimeException('Graph devolvió parámetros de cursor no autorizados.');
            }
        }
        return $url;
    }

    private function items(array $body, string $key): array
    {
        $items = $body[$key] ?? [];
        if (!is_array($items) || !array_is_list($items) || count($items) > 100) {
            throw new \RuntimeException('Página de polling inválida o superior a 100 elementos.');
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('El proveedor devolvió un elemento inválido.');
            }
        }
        return $items;
    }

    private function id(array $data, string $key): string
    {
        $value = $this->text($data, $key);
        if (!preg_match('/^[a-zA-Z0-9_@.!+~=-]{1,512}$/D', $value) || in_array($value, ['.', '..'], true)) {
            throw new \InvalidArgumentException('Identificador inválido: ' . $key . '.');
        }
        return rawurlencode($value);
    }

    private function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen($value) > 32768 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new \InvalidArgumentException('Campo de texto inválido: ' . $key . '.');
        }
        return $value;
    }

    private function timestamp(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?Z$/D', $value) || ($time = strtotime($value)) === false) {
            throw new \RuntimeException('El proveedor o estado devolvió una fecha UTC inválida.');
        }
        return $time;
    }

    private function iso(int $now): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $now);
    }

    private function optionalToken(mixed $token): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }
        if (!is_string($token) || strlen($token) > 32768 || preg_match('/[\x00-\x1f\x7f]/', $token)) {
            throw new \RuntimeException('El proveedor devolvió un cursor inválido.');
        }
        return $token;
    }

    private function progress(?string $previous, string $next): void
    {
        if ($previous === $next) {
            throw new \RuntimeException('El cursor de paginación no avanzó.');
        }
    }

    private function numberCompare(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private function pick(array $data, array $keys): array
    {
        return array_intersect_key($data, array_flip($keys));
    }

    private function event(string $id, string $type, array $data, ?string $occurredAt = null): array
    {
        $event = ['id' => strlen($id) <= 512 ? $id : hash('sha256', $id), 'type' => $type, 'data' => $data];
        if ($occurredAt !== null) {
            $this->timestamp($occurredAt);
            $event['occurred_at'] = $occurredAt;
        }
        return $event;
    }

    private function result(array $events, array $state, bool $hasMore): array
    {
        return ['events' => $events, 'state' => $state, 'has_more' => $hasMore];
    }

    private function redact(array $data, string $token): array
    {
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                $value = $this->redact($value, $token);
            } elseif (is_string($value)) {
                $value = str_replace($token, '[redactado]', $value);
            }
        }
        unset($value);
        return $data;
    }
}
