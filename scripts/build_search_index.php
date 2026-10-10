<?php
/**
 * Architectural Block Comment:
 * File: build_search_index.php
 * Purpose:
 *     This script crawls the narrative content hierarchy (Series -> Books -> Chapters -> Parts)
 *     and extracts markdown content to build a lightweight, flat JSON index (`search-index.json`).
 *     This index is utilized by the client-side search functionality to provide instant full-text 
 *     search capabilities across the narrative library.
 * 
 * Design Decisions & Future Maintenance:
 *     - Slugification: A custom `slugify` function is defined (if not already existing) to generate URL-friendly 
 *       slugs from folder names and titles. Ensure `iconv` extension is enabled in the PHP environment.
 *     - Frontmatter Stripping: It parses and removes YAML frontmatter from markdown files using regex, capturing the title 
 *       if available. If the regex fails, the raw content might still have frontmatter.
 *     - Content Cleaning: Basic HTML tag stripping (`strip_tags`) and simple markdown syntax stripping are employed to keep 
 *       the index lightweight. Advanced markdown parsing is skipped for performance.
 *     - Directory Structure Assumption: Assumes a strict `b*` (book), `c*` (chapter), and `p*.md` (part) folder structure.
 *     - Scalability: As the library grows, loading all files into memory (`$searchIndex` array) before writing the JSON 
 *       might become a memory bottleneck. Consider chunking or streaming if memory limits are reached.
 */

// Base directories for finding narrative books and the destination for the output index.
$narrativeDir = dirname(__DIR__) . '/books';
$indexDestFile = dirname(__DIR__, 2) . '/raggiesoft-assets/raggiesoft-books/json/search-index.json';

echo "Building search index...\n";

// Ensure the slugify helper function is declared only once.
if (!function_exists('slugify')) {
    /**
     * Converts a given text into a URL-friendly slug.
     */
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

// Initialize the master array that will hold all indexed search records.
$searchIndex = [];
// Retrieve all top-level series directories.
$seriesDirs = glob($narrativeDir . '/*', GLOB_ONLYDIR);

foreach ($seriesDirs as $seriesDir) {
    // Extract the series slug from the directory name.
    $seriesSlug = basename($seriesDir);
    
    // Read series title from landing.md frontmatter or fallback to directory name formatting.
    $seriesTitle = ucwords(str_replace('-', ' ', $seriesSlug));
    if (file_exists($seriesDir . '/landing.md')) {
        $landingContent = file_get_contents($seriesDir . '/landing.md');
        if (preg_match('/^---\s*\n(.*?)\n---/s', $landingContent, $matches)) {
            if (preg_match('/title:\s*"?([^"\n]+)"?/', $matches[1], $m)) {
                $seriesTitle = trim($m[1]);
            }
        }
    }

    // Traverse book directories (starting with 'b')
    $bDirs = glob($seriesDir . '/b*', GLOB_ONLYDIR);
    foreach ($bDirs as $bDir) {
        $bName = basename($bDir);
        $bookNum = intval(str_replace('b', '', $bName));
        $bookTitle = "Book {$bookNum}";
        
        // Attempt to read explicit book title from ovab.json metadata if present.
        $metaPath = $bDir . '/ovab.json';
        if (file_exists($metaPath)) {
            $meta = json_decode(file_get_contents($metaPath), true);
            if (isset($meta['title'])) $bookTitle = $meta['title'];
        }
        $bookSlug = slugify(strip_tags($bookTitle));

        // Traverse chapter directories (starting with 'c')
        $cDirs = glob($bDir . '/c*', GLOB_ONLYDIR);
        foreach ($cDirs as $cDir) {
            $cName = basename($cDir);
            $chapNum = intval(str_replace('c', '', $cName));
            $chapTitle = "Chapter {$chapNum}";
            
            // Attempt to read explicit chapter title from ovab.json metadata if present.
            $metaPath = $cDir . '/ovab.json';
            if (file_exists($metaPath)) {
                $meta = json_decode(file_get_contents($metaPath), true);
                if (isset($meta['title'])) $chapTitle = $meta['title'];
            }
            $chapSlug = slugify(strip_tags($chapTitle));

            // Traverse individual part markdown files (starting with 'p' and ending in '.md')
            $pFiles = glob($cDir . '/p*.md');
            foreach ($pFiles as $pFile) {
                $pName = basename($pFile);
                $partNum = intval(str_replace(['p', '.md'], '', $pName));
                
                $yamlTitle = "Part {$partNum}";
                $rawContent = file_get_contents($pFile);
                $body = $rawContent;
                
                // Strip YAML frontmatter from the body to avoid indexing metadata,
                // and extract the specific part title if available.
                if (preg_match('/^---([\s\S]*?)---/', ltrim($rawContent), $matches)) {
                    $body = str_replace($matches[0], '', $rawContent);
                    if (preg_match('/^title:\s*"?([^"\r\n]+)"?/m', $matches[1], $m)) {
                        $yamlTitle = trim($m[1]);
                    }
                }
                
                $partTitle = "Part {$partNum}: {$yamlTitle}";
                $partSlug = slugify(strip_tags($partTitle));
                
                // Construct the canonical routing URL for client navigation upon search selection.
                $routeUrl = "/raggiesoft-books/books/{$seriesSlug}/{$bookSlug}/{$chapSlug}/{$partSlug}";
                
                // Clean the body text for the index to minimize JSON payload size.
                $cleanBody = strip_tags($body); // Remove HTML tags
                $cleanBody = preg_replace('/[#_*\[\]>]/', '', $cleanBody); // Basic markdown character stripping
                $cleanBody = preg_replace('/\s+/', ' ', $cleanBody); // Collapse excessive whitespace/newlines
                $cleanBody = trim($cleanBody);

                // Append the constructed record to the master index array.
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

// Persist the entire index array to disk as a JSON file, without escaping forward slashes for cleaner URLs.
file_put_contents($indexDestFile, json_encode($searchIndex, JSON_UNESCAPED_SLASHES));
echo "  [Search] Search index built at: {$indexDestFile}\n";
?>
