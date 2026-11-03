<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;
use Throwable;

class GoogleSheetsNode implements NodeInterface
{
    private ?ClientInterface $client;
    private ?CredentialManager $credentials;

    public function __construct(?ClientInterface $client = null, ?CredentialManager $credentials = null)
    {
        $this->client = $client;
        $this->credentials = $credentials;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'google_sheets', 'name' => 'Google Sheets', 'category' => 'Community',
            'description' => 'Agrega filas, lee o actualiza un rango y busca filas en Google Sheets.',
            'icon' => 'Sheet', 'is_start' => false,
            'help_text' => 'Credencial Bearer Token / API Key: access token OAuth en API Key, sin Bearer; Sheets API habilitada y acceso al archivo. Lectura: spreadsheets.readonly; escritura: spreadsheets, o drive.file para archivos autorizados. Token sin renovación automática. Usa rango A1 finito con hoja explícita, como Hoja1!A1:D100 o \'Mis datos\'!A1:D100; máximo 1000 filas, 100 columnas y 10000 celdas. Lectura/búsqueda procesa hasta row_limit filas (100 por defecto); pagination.next_range permite continuar manualmente. Búsqueda: column_index desde 0 relativo al rango, igualdad tipada (números equivalentes; texto distingue mayúsculas); search_value textual o expresión escalar. Celdas omitidas al final se comparan como texto vacío. Escrituras RAW: texto no se interpreta como fórmula. values es lista de listas o expresión; null omite una celda, "" la vacía; filas/celdas ausentes se conservan. Actualizar no limpia el resto del rango. Agregar usa el rango para detectar la tabla y escribe después de ella, no necesariamente dentro del rango. Sin reintentos. Salidas: data.values, data.matches o data.updated_range y contadores. El polling de filas disponible por separado observa posiciones en un rango finito; no equivale a historial de edición ni inserciones lógicas. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'label' => 'Credencial OAuth', 'required' => true],
                'operation' => ['type' => 'select', 'label' => 'Operación', 'required' => true, 'default' => 'read_range', 'options' => [
                    ['value' => 'append_rows', 'label' => 'Agregar filas'], ['value' => 'read_range', 'label' => 'Leer rango'],
                    ['value' => 'update_range', 'label' => 'Actualizar rango'], ['value' => 'search_rows', 'label' => 'Buscar filas']
                ]],
                'spreadsheet_id' => ['type' => 'string', 'label' => 'ID del archivo (no URL)', 'required' => true],
                'range' => ['type' => 'string', 'label' => 'Rango A1 finito', 'required' => true, 'placeholder' => 'Hoja1!A1:D100'],
                'values' => ['type' => 'textarea', 'label' => 'Filas para agregar/actualizar (JSON o expresión)', 'required' => false, 'placeholder' => '[["Ana",10],["Luis",20]] o {{origen.rows}}'],
                'row_limit' => ['type' => 'number', 'label' => 'Filas a leer/buscar (1–1000)', 'required' => false, 'default' => 100],
                'column_index' => ['type' => 'number', 'label' => 'Columna para buscar (desde 0)', 'required' => false, 'default' => 0],
                'search_value' => ['type' => 'string', 'label' => 'Valor exacto para buscar', 'required' => false]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->prepare($parameters);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        // Engine ya aplica ExpressionEvaluator; no volver a interpolar los datos.
        $config = $this->prepare($parameters);
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($config['credential_id']);
        } catch (Throwable $e) {
            throw new RuntimeException('Google Sheets: no se pudo recuperar o descifrar la credencial en el gestor de p8p.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Google Sheets: la credencial seleccionada no existe.');
        }
        $token = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($token)
            || strlen($token) > 8192 || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', trim($token)) !== 1) {
            throw new InvalidArgumentException('Google Sheets: selecciona una credencial Bearer Token / API Key con un access token OAuth sin el prefijo Bearer.');
        }
        $token = trim($token);
        $write = in_array($config['operation'], ['append_rows', 'update_range'], true);
        $notice = $write ? ' La escritura puede haberse realizado; comprueba la hoja antes de repetirla.' : '';
        $method = $write ? ($config['operation'] === 'append_rows' ? 'POST' : 'PUT') : 'GET';
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($config['spreadsheet_id'])
            . '/values/' . rawurlencode($config['request_range']) . ($method === 'POST' ? ':append' : '');
        $options = ['headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
            'timeout' => 30, 'connect_timeout' => 10, 'verify' => true, 'allow_redirects' => false, 'http_errors' => false];
        if ($write) {
            $options['query'] = ['valueInputOption' => 'RAW', 'includeValuesInResponse' => 'false'];
            if ($method === 'POST') {
                $options['query']['insertDataOption'] = 'INSERT_ROWS';
            }
            $options['json'] = ['majorDimension' => 'ROWS', 'values' => $config['values']];
        } else {
            $options['query'] = ['majorDimension' => 'ROWS', 'valueRenderOption' => 'UNFORMATTED_VALUE', 'dateTimeRenderOption' => 'SERIAL_NUMBER'];
        }
        try {
            $response = ($this->client ?? new Client())->request($method, $url, $options);
            $status = $response->getStatusCode();
            $raw = $response->getBody()->read(2097153);
        } catch (Throwable $e) {
            throw new RuntimeException('Google Sheets: fallo de transporte o lectura de la respuesta.' . $notice);
        }
        $decoded = json_decode($raw);
        $body = $decoded instanceof \stdClass ? get_object_vars($decoded) : null;
        if ($status < 200 || $status >= 300) {
            $reason = match (true) {
                $status === 401 => 'token OAuth inválido, expirado o revocado; reemplázalo en el gestor',
                $status === 403 => 'acceso denegado; revisa scopes, permisos sobre el archivo, cuota y Sheets API habilitada',
                $status === 404 => 'archivo inexistente o inaccesible para la credencial',
                $status === 429 => 'límite de solicitudes o cuota alcanzado; espera antes de repetir',
                $status >= 500 => 'error del servicio Google Sheets',
                $status >= 300 && $status < 400 => 'redirección rechazada',
                default => 'solicitud rechazada; revisa rango y datos'
            };
            throw new RuntimeException("Google Sheets: {$reason} (HTTP {$status})." . $notice, $status);
        }
        try {
            if (strlen($raw) > 2097152 || !str_starts_with(ltrim($raw), '{') || !is_array($body) || isset($body['error'])) {
                throw new InvalidArgumentException();
            }
            $data = $write ? $this->writeResult($body, $config) : $this->readResult($body, $config);
            if (str_contains(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $token)) {
                throw new InvalidArgumentException();
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Google Sheets: respuesta JSON inválida, incompleta o superior a los límites admitidos.' . $notice, $status);
        }
        return ['success' => true, 'status_code' => $status, 'data' => $data];
    }

    private function prepare(array $parameters): array
    {
        $credential = $this->text($parameters['credential_id'] ?? null);
        $id = $this->text($parameters['spreadsheet_id'] ?? null);
        if (preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Google Sheets: ID del archivo inválido; no uses su URL.');
        }
        $operation = $parameters['operation'] ?? 'read_range';
        if (!in_array($operation, ['append_rows', 'read_range', 'update_range', 'search_rows'], true)) {
            throw new InvalidArgumentException('Google Sheets: operación inválida.');
        }
        $range = $this->parseRange($parameters['range'] ?? null);
        $config = ['credential_id' => $credential, 'spreadsheet_id' => $id, 'operation' => $operation,
            'range' => $range, 'request_range' => $range['text']];
        if (in_array($operation, ['append_rows', 'update_range'], true)) {
            $values = $parameters['values'] ?? null;
            if (is_string($values)) {
                try {
                    $values = json_decode($values, false, 32, JSON_THROW_ON_ERROR);
                } catch (Throwable $e) {
                    throw new InvalidArgumentException('Google Sheets: values debe ser una lista JSON de filas válida.');
                }
            }
            if (!is_array($values) || !array_is_list($values) || $values === [] || count($values) > 1000
                || ($operation === 'update_range' && count($values) > $range['rows'])) {
                throw new InvalidArgumentException('Google Sheets: values requiere de 1 a 1000 filas que quepan en el rango al actualizar.');
            }
            $cells = 0;
            $hasValue = false;
            foreach ($values as $row) {
                if (!is_array($row) || !array_is_list($row) || count($row) > $range['columns']) {
                    throw new InvalidArgumentException('Google Sheets: cada fila debe ser una lista dentro del ancho del rango.');
                }
                foreach ($row as $cell) {
                    $this->cell($cell, true);
                    $hasValue = $hasValue || $cell !== null;
                }
                $cells += count($row);
            }
            if ($cells > 10000 || !$hasValue || strlen(json_encode($values, JSON_THROW_ON_ERROR)) > 1048576) {
                throw new InvalidArgumentException('Google Sheets: values debe contener alguna celda no null y no superar 10000 celdas ni 1 MiB.');
            }
            $config['values'] = $values;
        } else {
            $limit = $this->integer($parameters['row_limit'] ?? 100, 1, 1000);
            $config['read_rows'] = min($limit, $range['rows']);
            $end = $range['start_row'] + $config['read_rows'] - 1;
            $config['request_range'] = $range['sheet'] . '!' . $range['start_col'] . $range['start_row'] . ':' . $range['end_col'] . $end;
            if ($operation === 'search_rows') {
                $config['column_index'] = $this->integer($parameters['column_index'] ?? 0, 0, $range['columns'] - 1);
                if (!array_key_exists('search_value', $parameters)) {
                    throw new InvalidArgumentException('Google Sheets: search_rows requiere search_value; texto vacío es válido.');
                }
                $this->cell($parameters['search_value'], false);
                $config['search_value'] = $parameters['search_value'];
            }
        }
        return $config;
    }

    private function parseRange(mixed $value): array
    {
        $value = $this->text($value);
        if (preg_match("/^('(?:[^']|'')+'|[A-Za-z_][A-Za-z0-9_]*)!([A-Z]{1,3})([1-9][0-9]{0,6})(?::([A-Z]{1,3})([1-9][0-9]{0,6}))?$/uD", $value, $m) !== 1) {
            throw new InvalidArgumentException('Google Sheets: usa un rango A1 finito con hoja explícita, por ejemplo Hoja1!A1:D100.');
        }
        $startCol = $this->column($m[2]); $endCol = $this->column($m[4] ?? $m[2]);
        $startRow = (int)$m[3]; $endRow = (int)($m[5] ?? $m[3]);
        $rows = $endRow - $startRow + 1; $columns = $endCol - $startCol + 1;
        if ($endCol > 18278 || $rows < 1 || $columns < 1 || $rows > 1000 || $columns > 100 || $rows * $columns > 10000) {
            throw new InvalidArgumentException('Google Sheets: rango invertido o superior a 1000 filas, 100 columnas o 10000 celdas.');
        }
        return ['text' => $value, 'sheet' => $m[1], 'start_col' => $m[2], 'end_col' => $m[4] ?? $m[2],
            'start_row' => $startRow, 'end_row' => $endRow, 'rows' => $rows, 'columns' => $columns];
    }

    private function readResult(array $body, array $config): array
    {
        if (!is_string($body['range'] ?? null) || ($body['majorDimension'] ?? null) !== 'ROWS') {
            throw new InvalidArgumentException();
        }
        $this->text($body['range']);
        $values = array_key_exists('values', $body) ? $body['values'] : [];
        if (!is_array($values) || !array_is_list($values) || count($values) > $config['read_rows']) {
            throw new InvalidArgumentException();
        }
        foreach ($values as $row) {
            if (!is_array($row) || !array_is_list($row) || count($row) > $config['range']['columns']) {
                throw new InvalidArgumentException();
            }
            foreach ($row as $cell) {
                $this->cell($cell, false);
            }
        }
        $range = $config['range'];
        $nextStart = $range['start_row'] + $config['read_rows'];
        $data = ['range' => $body['range'], 'requested_range' => $config['request_range'], 'values' => $values, 'count' => count($values),
            'pagination' => ['row_limit' => $config['read_rows'], 'max_cells' => 10000,
                'has_more_range' => $nextStart <= $range['end_row'],
                'next_range' => $nextStart <= $range['end_row'] ? $range['sheet'] . '!' . $range['start_col'] . $nextStart . ':' . $range['end_col'] . $range['end_row'] : null]];
        if ($config['operation'] === 'search_rows') {
            $matches = [];
            foreach ($values as $index => $row) {
                $cell = $row[$config['column_index']] ?? '';
                $needle = $config['search_value'];
                $numeric = (is_int($cell) || is_float($cell)) && (is_int($needle) || is_float($needle));
                if ($numeric ? $cell == $needle : $cell === $needle) {
                    $matches[] = ['row_number' => $range['start_row'] + $index, 'values' => $row];
                }
            }
            unset($data['values']);
            $data['matches'] = $matches;
            $data['count'] = count($matches);
            $data['scanned_rows'] = count($values);
        }
        return $data;
    }

    private function writeResult(array $body, array $config): array
    {
        if (($body['spreadsheetId'] ?? null) !== $config['spreadsheet_id']) {
            throw new InvalidArgumentException();
        }
        $updates = $config['operation'] === 'append_rows' ? ($body['updates'] ?? null) : $body;
        if ($updates instanceof \stdClass) {
            $updates = get_object_vars($updates);
        }
        if (!is_array($updates) || !is_string($updates['updatedRange'] ?? null)) {
            throw new InvalidArgumentException();
        }
        $data = ['updated_range' => $this->text($updates['updatedRange'])];
        foreach (['updatedRows' => 'updated_rows', 'updatedColumns' => 'updated_columns', 'updatedCells' => 'updated_cells'] as $key => $outputKey) {
            $maximum = match ($key) {
                'updatedRows' => count($config['values']),
                'updatedColumns' => $config['range']['columns'],
                default => 10000
            };
            $data[$outputKey] = $this->integer($updates[$key] ?? null, 0, $maximum);
        }
        return $data;
    }

    private function cell(mixed $value, bool $allowNull): void
    {
        if (($value === null && $allowNull) || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
            return;
        }
        if (is_string($value) && mb_check_encoding($value, 'UTF-8') && strlen($value) <= 50000
            && !str_contains($value, "\0")) {
            return;
        }
        throw new InvalidArgumentException('Google Sheets: las celdas requieren texto UTF-8 de hasta 50000 bytes, número finito o booleano; null solo omite celdas al escribir.');
    }

    private function text(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > 1024 || !mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1 || preg_match('/{{\s*[A-Za-z0-9_.]+\s*}}/', $value) === 1) {
            throw new InvalidArgumentException('Google Sheets: campo requerido vacío, inválido o con expresión sin resolver.');
        }
        return trim($value);
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,6})$/D', $value) === 1) {
            $value = (int)$value;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException('Google Sheets: entero fuera del intervalo admitido.');
        }
        return $value;
    }

    private function column(string $value): int
    {
        $column = 0;
        foreach (str_split($value) as $letter) {
            $column = $column * 26 + ord($letter) - 64;
        }
        return $column;
    }
}
