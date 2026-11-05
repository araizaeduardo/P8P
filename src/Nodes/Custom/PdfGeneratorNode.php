<?php

namespace P8P\Nodes\Custom;

use P8P\Interfaces\NodeInterface;

class PdfGeneratorNode implements NodeInterface
{
    public function __construct(private ?\Closure $renderer = null)
    {
    }

    public function getSchema(): array
    {
        return ['type' => 'pdf_generator', 'name' => 'PDF Generator & Merger', 'category' => 'Community', 'icon' => 'FileText', 'is_start' => false,
            'description' => 'PDF desde texto/HTML restringido o combinación de PDF base64 en orden.',
            'help_text' => 'Requiere dompdf/dompdf ^3.1 para texto/HTML y setasign/fpdi ^2.6 + setasign/fpdf ^1.8 para combinar. Sin PHP/JS, imágenes, CSS externo, enlaces, recursos remotos ni archivos locales de entrada. HTML semántico permitido: párrafos, encabezados, tablas y listas; fuente DejaVu Sans incorporada, 11pt. Texto/HTML máximo 256 KiB; 2–10 PDF de 5 MiB, total20 MiB, máximo100 páginas/salida10 MiB. Salida content_base64, mime_type, filename, size, page_count y sha256. No credenciales ni rutas persistentes. FPDI gratuito puede rechazar PDF cifrados o con compresión no compatible.',
            'parameters' => [
                'operation' => ['type' => 'select', 'label' => 'Operación', 'required' => true, 'default' => 'text', 'options' => [['value' => 'text', 'label' => 'Texto a PDF'], ['value' => 'html', 'label' => 'HTML restringido a PDF'], ['value' => 'merge', 'label' => 'Combinar PDF']]],
                'content' => ['type' => 'textarea', 'label' => 'Texto o HTML'],
                'pdfs' => ['type' => 'textarea', 'label' => 'Array JSON de cadenas base64 PDF (orden de combinación)'],
                'paper' => ['type' => 'select', 'label' => 'Papel', 'default' => 'A4', 'options' => [['value' => 'A4', 'label' => 'A4'], ['value' => 'letter', 'label' => 'Letter']]],
                'orientation' => ['type' => 'select', 'label' => 'Orientación', 'default' => 'portrait', 'options' => [['value' => 'portrait', 'label' => 'Vertical'], ['value' => 'landscape', 'label' => 'Horizontal']]],
                'filename' => ['type' => 'string', 'label' => 'Nombre de salida', 'default' => 'document.pdf']
            ]];
    }

    public function validate(array $parameters): bool
    {
        try { $this->parameters($parameters); return true; } catch (\Throwable $e) { return false; }
    }

    private function parameters(array $p): array
    {
        $op = $p['operation'] ?? 'text';
        $filename = $p['filename'] ?? 'document.pdf';
        if (!in_array($op, ['text','html','merge'], true) || !is_string($filename) || !preg_match('/^[A-Za-z0-9_-]{1,80}\.pdf$/Di', $filename)
            || !in_array($p['paper'] ?? 'A4', ['A4','letter'], true) || !in_array($p['orientation'] ?? 'portrait', ['portrait','landscape'], true)) { throw new \InvalidArgumentException('PDF: operación, nombre, papel u orientación inválidos.'); }
        $p['operation'] = $op;
        $p['filename'] = $filename;
        if ($op === 'merge') {
            $pdfs = $p['pdfs'] ?? null;
            if (is_string($pdfs)) { $pdfs = json_decode($pdfs, true, 16, JSON_THROW_ON_ERROR); }
            if (!is_array($pdfs) || !array_is_list($pdfs) || count($pdfs) < 2 || count($pdfs) > 10) { throw new \InvalidArgumentException('PDF: combinar requiere un array de 2 a 10 PDF base64.'); }
            $decoded = []; $total = 0;
            foreach ($pdfs as $encoded) {
                if (!is_string($encoded) || strlen($encoded) > 6990510 || ($data = base64_decode($encoded, true)) === false || strlen($data) > 5242880 || !str_starts_with($data, '%PDF-')) { throw new \InvalidArgumentException('PDF: archivo base64 inválido o mayor a 5 MiB.'); }
                $total += strlen($data);
                if ($total > 20971520) { throw new \InvalidArgumentException('PDF: entradas mayores a 20 MiB.'); }
                $decoded[] = $data;
            }
            $p['documents'] = $decoded;
        } else {
            $content = $p['content'] ?? null;
            if (!is_string($content) || $content === '' || strlen($content) > 262144 || !mb_check_encoding($content, 'UTF-8')) { throw new \InvalidArgumentException('PDF: texto UTF-8 de 1 a 256 KiB requerido.'); }
            $p['html'] = $op === 'text' ? '<pre>' . htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>' : $this->safeHtml($content);
        }
        return $p;
    }

    private function safeHtml(string $html): string
    {
        if (!class_exists(\DOMDocument::class) || preg_match('/<!|<\?|&(?:#0*0|#x0+);/i', $html)) { throw new \InvalidArgumentException('PDF: HTML inválido, declaraciones o instrucciones no permitidas.'); }
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $loaded = $doc->loadHTML('<meta charset="UTF-8"><body>' . $html . '</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$loaded) { throw new \InvalidArgumentException('PDF: HTML inválido.'); }
        $allowed = ['html','head','meta','body','p','div','span','br','h1','h2','h3','h4','h5','h6','pre','strong','b','em','i','u','ul','ol','li','table','thead','tbody','tfoot','tr','td','th','hr','code'];
        $nodes = $doc->getElementsByTagName('*');
        if ($nodes->length > 5000) { throw new \InvalidArgumentException('PDF: HTML demasiado complejo.'); }
        foreach ($nodes as $node) {
            if (!in_array(strtolower($node->tagName), $allowed, true)) { throw new \InvalidArgumentException('PDF: etiqueta HTML no permitida.'); }
            $depth = 0;
            for ($parent = $node; $parent; $parent = $parent->parentNode) { if (++$depth > 64) { throw new \InvalidArgumentException('PDF: HTML demasiado profundo.'); } }
            foreach (iterator_to_array($node->attributes) as $attribute) {
                if ($node->tagName === 'meta' && $attribute->name === 'charset' && strtoupper($attribute->value) === 'UTF-8') { continue; }
                if (!in_array($node->tagName, ['td','th'], true) || !in_array($attribute->name, ['colspan','rowspan'], true) || !preg_match('/^(?:[1-9]|1[0-9]|20)$/D', $attribute->value)) { throw new \InvalidArgumentException('PDF: atributos, estilos o recursos HTML no permitidos.'); }
            }
        }
        $body = $doc->getElementsByTagName('body')->item(0);
        $out = '';
        foreach ($body->childNodes as $child) { $out .= $doc->saveHTML($child); }
        return $out;
    }

    public function execute(array $parameters, array $context): array
    {
        // Las expresiones ya han sido resueltas por Engine; nunca ejecutar plantillas PHP.
        $p = $this->parameters($parameters);
        if (!$this->renderer && (($p['operation'] === 'merge' && (!class_exists(\setasign\Fpdi\Fpdi::class) || !class_exists(\FPDF::class)))
            || ($p['operation'] !== 'merge' && !class_exists(\Dompdf\Dompdf::class)))) {
            throw new \RuntimeException('PDF: faltan dependencias autorizables: dompdf/dompdf ^3.1, setasign/fpdi ^2.6 y setasign/fpdf ^1.8. No se instala nada automáticamente.');
        }
        try {
            [$bytes, $pages] = $this->renderer ? ($this->renderer)($p) : ($p['operation'] === 'merge' ? $this->merge($p) : $this->render($p));
            if (!is_string($bytes) || !str_starts_with($bytes, '%PDF-') || strlen($bytes) > 10485760 || !is_int($pages) || $pages < 1 || $pages > 100) { throw new \RuntimeException(); }
            return ['success' => true, 'operation' => $p['operation'], 'content_base64' => base64_encode($bytes), 'mime_type' => 'application/pdf', 'filename' => $p['filename'], 'size' => strlen($bytes), 'page_count' => $pages, 'sha256' => hash('sha256', $bytes)];
        } catch (\Throwable $e) { throw new \RuntimeException('PDF: no se pudo generar/combinar; documento incompatible, cifrado, inválido o límite de recursos excedido.'); }
    }

    private function render(array $p): array
    {
        $dir = sys_get_temp_dir() . '/p8p-pdf-' . bin2hex(random_bytes(16));
        if (!mkdir($dir, 0700)) { throw new \RuntimeException(); }
        try {
            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', false);
            $options->set('isPhpEnabled', false);
            $options->set('isJavascriptEnabled', false);
            $options->set('chroot', [$dir]);
            $options->set('tempDir', $dir);
            $options->set('fontCache', $dir);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->setAllowedProtocols([]);
            $pdf = new \Dompdf\Dompdf($options);
            $pdf->setPaper($p['paper'] ?? 'A4', $p['orientation'] ?? 'portrait');
            $pdf->loadHtml('<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:"DejaVu Sans";font-size:11pt}pre{white-space:pre-wrap;font-family:"DejaVu Sans"}table{border-collapse:collapse}td,th{border:1px solid #888;padding:4pt}</style></head><body>' . $p['html'] . '</body></html>', 'UTF-8');
            $pdf->render();
            $pages = $pdf->getCanvas()->get_page_count();
            if ($pages > 100) { throw new \RuntimeException(); }
            return [$pdf->output(), $pages];
        } finally {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($dir);
        }
    }

    private function merge(array $p): array
    {
        $pdf = new \setasign\Fpdi\Fpdi();
        $pages = 0;
        foreach ($p['documents'] as $bytes) {
            $count = $pdf->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($bytes));
            if (($pages += $count) > 100) { throw new \RuntimeException(); }
            for ($page = 1; $page <= $count; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }
        }
        return [$pdf->Output('S'), $pages];
    }
}
