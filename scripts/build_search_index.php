<?php
// Builds the search-index.json for client-side search.
// It creates a lightweight index of titles, URLs, and plain text content.

$narrativeDir = dirname(__DIR__) . '/books';
$indexDestFile = dirname(__DIR__, 2) . '/raggiesoft-assets/raggiesoft-books/json/search-index.json';

echo "Building search index...\n";

if (!function_exists('slugify')) {
    function slugify($text) {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = preg_replace('~-+~', '-', $text);
        $text = strtolower($text);
        return $text;
    }
}

$searchIndex = [];
$seriesDirs = glob($narrativeDir . '/*', GLOB_ONLYDIR);

foreach ($seriesDirs as $seriesDir) {
    $seriesSlug = basename($seriesDir);
    
    // Read series title from landing.md or directory name
    $seriesTitle = ucwords(str_replace('-', ' ', $seriesSlug));
    if (file_exists($seriesDir . '/landing.md')) {
        $landingContent = file_get_contents($seriesDir . '/landing.md');
        if (preg_match('/^---\s*\n(.*?)\n---/s', $landingContent, $matches)) {
            if (preg_match('/title:\s*"?([^"\n]+)"?/', $matches[1], $m)) {
                $seriesTitle = trim($m[1]);
            }
        }
    }

    $bDirs = glob($seriesDir . '/b*', GLOB_ONLYDIR);
    foreach ($bDirs as $bDir) {
        $bName = basename($bDir);
        $bookNum = intval(str_replace('b', '', $bName));
        $bookTitle = "Book {$bookNum}";
        
        $metaPath = $bDir . '/ovab.json';
        if (file_exists($metaPath)) {
            $meta = json_decode(file_get_contents($metaPath), true);
            if (isset($meta['title'])) $bookTitle = $meta['title'];
        }
        $bookSlug = slugify(strip_tags($bookTitle));

        $cDirs = glob($bDir . '/c*', GLOB_ONLYDIR);
        foreach ($cDirs as $cDir) {
            $cName = basename($cDir);
            $chapNum = intval(str_replace('c', '', $cName));
            $chapTitle = "Chapter {$chapNum}";
            
            $metaPath = $cDir . '/ovab.json';
            if (file_exists($metaPath)) {
                $meta = json_decode(file_get_contents($metaPath), true);
                if (isset($meta['title'])) $chapTitle = $meta['title'];
            }
            $chapSlug = slugify(strip_tags($chapTitle));

            $pFiles = glob($cDir . '/p*.md');
            foreach ($pFiles as $pFile) {
                $pName = basename($pFile);
                $partNum = intval(str_replace(['p', '.md'], '', $pName));
                
                $yamlTitle = "Part {$partNum}";
                $rawContent = file_get_contents($pFile);
                $body = $rawContent;
                
                // Strip frontmatter
                if (preg_match('/^---([\s\S]*?)---/', ltrim($rawContent), $matches)) {
                    $body = str_replace($matches[0], '', $rawContent);
                    if (preg_match('/^title:\s*"?([^"\r\n]+)"?/m', $matches[1], $m)) {
                        $yamlTitle = trim($m[1]);
                    }
                }
                
                $partTitle = "Part {$partNum}: {$yamlTitle}";
                $partSlug = slugify(strip_tags($partTitle));
                
                $routeUrl = "/raggiesoft-books/books/{$seriesSlug}/{$bookSlug}/{$chapSlug}/{$partSlug}";
                
                // Clean the body text for the index
                $cleanBody = strip_tags($body); // Remove HTML
                $cleanBody = preg_replace('/[#_*\[\]>]/', '', $cleanBody); // Basic markdown strip
                $cleanBody = preg_replace('/\s+/', ' ', $cleanBody); // collapse whitespace
                $cleanBody = trim($cleanBody);

                $searchIndex[] = [
                    'series' => $seriesTitle,
                    'book' => $bookTitle,
                    'chapter' => $chapTitle,
                    'title' => $partTitle,
                    'url' => $routeUrl,
                    'content' => $cleanBody
                ];
            }
        }
    }
}

file_put_contents($indexDestFile, json_encode($searchIndex, JSON_UNESCAPED_SLASHES));
echo "  [Search] Search index built at: {$indexDestFile}\n";
?>
