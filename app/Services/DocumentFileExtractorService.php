<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Writer\HTML as HtmlWriter;

class DocumentFileExtractorService
{
    /**
     * Extracts a .docx file's content as HTML suitable for a Document's `content` field.
     * PhpWord already reads Word2007 files as well as writing them; its HTML writer's
     * getContent() produces a full HTML document, so this trims it down to just the body.
     */
    public function extractDocxHtml(string $filePath): string
    {
        $phpWord = IOFactory::load($filePath);
        $fullHtml = (new HtmlWriter($phpWord))->getContent();

        return $this->extractBody($fullHtml);
    }

    /**
     * Wraps each line of a plain text file in its own paragraph.
     */
    public function extractTxtHtml(string $filePath): string
    {
        $text = (string) file_get_contents($filePath);
        $lines = preg_split('/\R/', trim($text)) ?: [];

        $paragraphs = array_map(
            fn (string $line) => '<p>'.($line !== '' ? e($line) : '&nbsp;').'</p>',
            $lines
        );

        $html = implode('', $paragraphs);

        return $html !== '' ? $html : '<p>No content.</p>';
    }

    /**
     * PhpWord's HTML writer emits a full <html><head>...<body>...</body></html> document —
     * this extracts just the body's inner markup. The reverse of
     * DocumentController::normalizeHtmlForWord() (content -> PhpWord's strict-XML importer);
     * here we're producing ordinary HTML for Tiptap's already-lenient parser, so saveHTML()
     * is enough — no need for saveXML()'s well-formedness guarantee.
     */
    private function extractBody(string $html): string
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '<p>No content.</p>';
        }

        $normalized = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $normalized .= $dom->saveHTML($child);
        }

        return $normalized !== '' ? $normalized : '<p>No content.</p>';
    }
}
