<?php

declare(strict_types=1);

namespace PDF\HtmlToPdf\Resource;

use PDF\HtmlToPdf\Exception\ConversionException;

/** Pure reference resolution: the result is only a key into an explicit catalog. */
final class CatalogUri
{
    public function resolve(string $reference, ?string $base = null): string
    {
        $uri = $this->parts($reference);
        $parent = $this->parts($base ?? '');
        if ($uri['scheme'] !== null) {
            $path = $uri['path'];
        } else {
            $uri['scheme'] = $parent['scheme'];
            if ($uri['authority'] !== null) {
                $path = $uri['path'];
            } else {
                $uri['authority'] = $parent['authority'];
                if ($uri['path'] === '') {
                    $path = $parent['path'];
                    $uri['query'] ??= $parent['query'];
                } elseif (str_starts_with($uri['path'], '/')) {
                    $path = $uri['path'];
                } else {
                    $slash = strrpos($parent['path'], '/');
                    $prefix = $slash === false ? ($parent['authority'] !== null ? '/' : '') : substr($parent['path'], 0, $slash + 1);
                    $path = $prefix . $uri['path'];
                }
            }
        }
        $result = ($uri['scheme'] !== null ? $uri['scheme'] . ':' : '')
            . ($uri['authority'] !== null ? '//' . $uri['authority'] : '')
            . $this->removeDotSegments($path)
            . ($uri['query'] !== null ? '?' . $uri['query'] : '');
        if ($result === '') {
            throw new ConversionException('A stylesheet reference must identify an explicit catalog resource.');
        }
        return $result;
    }

    /** @return array{scheme:?string,authority:?string,path:string,query:?string} */
    private function parts(string $uri): array
    {
        if (!mb_check_encoding($uri, 'UTF-8') || preg_match('/[\x00-\x20\x7f]/', $uri) === 1
            || preg_match('~^(?:([a-zA-Z][a-zA-Z0-9+.-]*):)?(?://([^/?\#]*))?([^?\#]*)(?:\?([^\#]*))?(?:\#.*)?$~D', $uri, $parts, PREG_UNMATCHED_AS_NULL) !== 1) {
            throw new ConversionException('Invalid explicit stylesheet resource URI.');
        }
        return ['scheme' => $parts[1], 'authority' => $parts[2], 'path' => $parts[3], 'query' => $parts[4]];
    }

    private function removeDotSegments(string $input): string
    {
        $absolute = str_starts_with($input, '/');
        $output = '';
        while ($input !== '') {
            if (str_starts_with($input, '../')) {
                $input = substr($input, 3);
            } elseif (str_starts_with($input, './')) {
                $input = substr($input, 2);
            } elseif (str_starts_with($input, '/./') || $input === '/.') {
                $input = '/' . substr($input, 3);
            } elseif (str_starts_with($input, '/../') || $input === '/..') {
                $input = '/' . substr($input, 4);
                $slash = strrpos($output, '/');
                $output = $slash === false ? '' : substr($output, 0, $slash);
            } elseif ($input === '.' || $input === '..') {
                $input = '';
            } else {
                $slash = strpos($input, '/', $input[0] === '/' ? 1 : 0);
                $take = $slash === false ? strlen($input) : $slash;
                $output .= substr($input, 0, $take);
                $input = substr($input, $take);
            }
        }
        return $absolute ? $output : ltrim($output, '/');
    }
}
