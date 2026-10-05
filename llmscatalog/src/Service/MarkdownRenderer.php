<?php

namespace LlmsCatalog\Service;

class MarkdownRenderer
{
    /** Convert storefront description HTML to a small, safe Markdown subset. */
    public function htmlToMarkdown(?string $html, int $maxBytes = 98304): string
    {
        $html = (string) $html;
        if ($html === '') return '';
        if (!class_exists('DOMDocument')) return $this->fallbackHtmlText($html);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8"><div id="llms-root">' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        catch (\Throwable $exception) {
            SafeDiagnosticLogger::log('public.markdown.parse', 0, $exception);
            return $this->fallbackHtmlText($html);
        }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $root = $dom->getElementById('llms-root');
        if (!$root) return $this->fallbackHtmlText($html);
        $out = $this->renderHtmlChildren($root);
        $out = preg_replace("/\\n{3,}/", "\n\n", trim($out)) ?: '';
        if (strlen($out) > $maxBytes) {
            $sections = preg_split("/\\n\\s*\\n/", $out) ?: [];
            $kept = '';
            foreach ($sections as $section) {
                $candidate = $kept === '' ? $section : $kept . "\n\n" . $section;
                if (strlen($candidate) > $maxBytes) break;
                $kept = $candidate;
            }
            $out = $kept;
        }
        return $out;
    }

    private function fallbackHtmlText(string $html): string
    {
        $html = preg_replace('#<(script|style|form|iframe|object|embed|svg|noscript)\b[^>]*>.*?</\1\s*>#is', '', $html) ?: '';
        $html = preg_replace('/<!--[\s\S]*?-->/', '', $html) ?: '';
        return $this->text($html);
    }

    private function renderHtmlChildren(\DOMNode $node, int $depth = 0, int $listIndex = 0): string
    {
        if ($depth > 32) return '';
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) { $out .= $this->escapeInline(html_entity_decode($child->nodeValue ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')); continue; }
            if (!$child instanceof \DOMElement) continue;
            $tag = strtolower($child->tagName);
            if (in_array($tag, ['script','style','form','input','button','select','textarea','iframe','object','embed','svg','noscript'], true)) continue;
            if ($tag === 'br') { $out .= "\n"; continue; }
            if ($tag === 'ul' || $tag === 'ol') {
                $number = 1;
                foreach ($child->childNodes as $listItem) {
                    if (!$listItem instanceof \DOMElement || strtolower($listItem->tagName) !== 'li') continue;
                    $item = trim($this->renderHtmlChildren($listItem, $depth + 1));
                    if ($item !== '') { $out .= ($tag === 'ol' ? $number . '. ' : '- ') . $item . "\n"; $number++; }
                }
                $out .= "\n"; continue;
            }
            $inner = $this->renderHtmlChildren($child, $depth + 1, $tag === 'ol' ? 1 : 0);
            $inner = trim($inner);
            if ($inner === '') continue;
            if ($tag === 'a') {
                $href = trim($child->getAttribute('href'));
                if (preg_match('#^https?://#i', $href)) $inner = '[' . $inner . '](' . str_replace([' ', ')', '('], ['%20', '%29', '%28'], $href) . ')';
            } elseif (in_array($tag, ['strong','b'], true)) $inner = '**' . $inner . '**';
            elseif (in_array($tag, ['em','i'], true)) $inner = '*' . $inner . '*';
            elseif (preg_match('/^h[1-6]$/', $tag)) $inner = '### ' . $inner;
            elseif ($tag === 'p' || $tag === 'div' || $tag === 'section' || $tag === 'article' || $tag === 'blockquote') $inner .= "\n\n";
            $out .= $inner;
        }
        return $out;
    }

    private function escapeInline(string $value): string
    {
        $value = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/u', ' ', $value) ?: '';
        return str_replace(['\\', '`', '*', '_', '[', ']', '<', '>'], ['\\\\', '\\`', '\\*', '\\_', '\\[', '\\]', '&lt;', '&gt;'], $value);
    }

    public function text(?string $value): string
    {
        $value = trim(strip_tags((string) $value));
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?: '';
        return trim(preg_replace('/\s+/u', ' ', $value) ?: '');
    }

    public function safeText(?string $value): string
    {
        $text = $this->text($value);
        return strtr($text, ['\\'=>'\\\\','`'=>'\\`','*'=>'\\*','_'=>'\\_','{'=>'\\{','}'=>'\\}','['=>'\\[',']'=>'\\]','<'=>'&lt;','>'=>'&gt;','#'=>'\\#','+'=>'\\+','|'=>'\\|']);
    }

    public function link(string $label, string $url, ?string $description = null): string
    {
        $label = str_replace([']', '['], ['\\]', '\\['], $this->text($label));
        $url = str_replace([' ', ')', '('], ['%20', '%29', '%28'], trim($url));
        $line = '- [' . $label . '](' . $url . ')';
        $description = $this->text($description);

        return $description !== '' ? $line . ': ' . $description : $line;
    }

    public function truncate(string $value, int $maxBytes, ?string $notice = null): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $suffix = "\n\n> " . ($notice ?? 'This index is truncated by the configured output limit. Use the linked product endpoints or the shop catalog for additional items.') . "\n";
        if (strlen($suffix) > $maxBytes) {
            return $this->utf8Prefix($suffix, max(0, $maxBytes));
        }
        $available = $maxBytes - strlen($suffix);
        $output = '';
        foreach (explode("\n", $value) as $line) {
            $candidate = $output === '' ? $line : $output . "\n" . $line;
            if (strlen($candidate) <= $available) {
                $output = $candidate;
            } elseif ($line !== '') {
                // Keep links and UTF-8 text whole; an oversized optional line is omitted.
                continue;
            }
        }
        $result = rtrim($output, "\n") . $suffix;
        return strlen($result) <= $maxBytes ? $result : $this->utf8Prefix($result, $maxBytes);
    }

    private function utf8Prefix(string $value, int $bytes): string
    {
        $prefix = substr($value, 0, $bytes);
        while ($prefix !== '' && !preg_match('//u', $prefix)) $prefix = substr($prefix, 0, -1);
        return $prefix;
    }
}
