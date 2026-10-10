<?php
/**
 * ============================================================================
 * ARCHITECTURE & MODULE OVERVIEW: lump_narratives.php
 * ============================================================================
 * Purpose:
 * This script aggregates fragmented Markdown narrative files (parts) into 
 * consolidated book-level Markdown files. It reads the 'katie.json' manifests 
 * to determine the correct structural hierarchy and ordering.
 * 
 * Architectural Role:
 * Functions as a build/compile step for the narrative system. It takes the 
 * normalized source-of-truth files in `/books` and compiles them into a 
 * delivery or processing format in `/books-ai-lump`. This output is likely 
 * optimized for LLM ingestion or general reading.
 * 
 * Key Components:
 * 1. Directory Management: Automatically cleans and provisions the target 
 *    `/books-ai-lump` directory to ensure idempotent runs.
 * 2. Manifest Driven: Relies exclusively on `katie.json` for structural 
 *    routing (Series -> Book -> Chapter -> Part) instead of file system 
 *    alphabetization.
 * 3. Content Merging: Extracts YAML frontmatter from individual parts, 
 *    reformats relevant metadata into Markdown text, and concatenates 
 *    the main content under appropriate heading levels.
 * 
 * Maintenance Notes:
 * - The recursive directory deletion (`deleteDir`) is potent; ensure path 
 *   variables (`$lumpBaseDir`) are always strictly validated.
 * - Changes to frontmatter keys in `migrate_frontmatter.php` must be 
 *   reflected here where frontmatter is parsed via regex.
 * ============================================================================
 */

echo "Starting AI Context Lumper...\n";
echo "Validating directories...\n";

// Define our working directories based on the script's location
// Ensures script functions correctly regardless of where it is executed from.
$baseDir = dirname(__DIR__); // Moves up to /raggiesoft-narratives
$booksBaseDir = $baseDir . '/books';
$lumpBaseDir = $baseDir . '/books-ai-lump';

if (!is_dir($booksBaseDir)) {
    die("Error: Base books directory not found at {$booksBaseDir}\n");
}

// Ensure the target base directory exists. If it does, WIPE IT CLEAN FIRST.
// This guarantees that removed books/parts do not linger in the lump output.
if (is_dir($lumpBaseDir)) {
    echo "Cleaning existing lump directory: /books-ai-lump/...\n";
    
    /**
     * Recursively deletes a directory and its contents.
     * Caution: Irreversible filesystem operation.
     */
    function deleteDir($dirPath) {
        if (!is_dir($dirPath)) {
            return;
        }
        $files = array_diff(scandir($dirPath), array('.', '..'));
        foreach ($files as $file) {
            $path = $dirPath . '/' . $file;
            is_dir($path) ? deleteDir($path) : unlink($path);
        }
        rmdir($dirPath);
    }
    deleteDir($lumpBaseDir);
}

// Recreate the fresh, empty base directory
echo "Creating fresh master output directory: /books-ai-lump/\n";
mkdir($lumpBaseDir, 0755, true);

/**
 * Helper function to create clean filenames and folder names
 * Converts strings to URL/file-system safe slugs.
 */
function slugify($string) {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string), '-'));
    return preg_replace('/-+/', '-', $slug);
}

// Scan for all narrative series folders inside the /books directory
// Filters to only include directories, skipping rogue files at the root level.
$narrativeDirs = array_filter(glob($booksBaseDir . '/*'), 'is_dir');

if (empty($narrativeDirs)) {
    die("No narrative folders found in {$booksBaseDir}\n");
}

// Loop through each series (e.g., /books/rachel)
foreach ($narrativeDirs as $narrativeDir) {
    $narrativeName = basename($narrativeDir); // e.g., 'rachel'
    $manifestFile = $narrativeDir . '/katie.json';
    
    echo "\n========================================================\n";
    echo "Scanning Narrative Series: {$narrativeName}\n";
    echo "========================================================\n";

    // A katie.json manifest is strictly required to determine sequence.
    if (!file_exists($manifestFile)) {
        echo "[Skipping] No katie.json found in /books/{$narrativeName}/\n";
        continue;
    }

    echo "Loading manifest from: /books/{$narrativeName}/katie.json...\n";
    $katie = json_decode(file_get_contents($manifestFile), true);
    
    // Fail gracefully on bad JSON
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "[Error] Invalid JSON syntax in /books/{$narrativeName}/katie.json\n";
        continue;
    }

    // Handle potential schema variations: root object vs nested under 'books'
    $books = isset($katie['books']) ? $katie['books'] : $katie;
    $seriesTitle = isset($katie['series_title']) ? $katie['series_title'] : $narrativeName;
    
    $bookCount = count($books);
    echo "Successfully parsed {$bookCount} books in '{$seriesTitle}'.\n\n";

    // Create the specific narrative folder inside books-ai-lump
    // Isolates output by series for organization.
    $seriesLumpDir = $lumpBaseDir . '/' . $narrativeName;
    if (!is_dir($seriesLumpDir)) {
        echo "  [Setup] Creating directory: /books-ai-lump/{$narrativeName}/\n";
        mkdir($seriesLumpDir, 0755, true);
    }

    // Process the hierarchy: Books -> Chapters -> Parts
    foreach ($books as $book) {
        echo "  Processing Book: {$book['book_title']}...\n";
        
        // Format the directory and filename (e.g., 001-book-1-the-delaney-street-years.md)
        // Pad the book number to enforce alphabetical file sorting that matches logical order.
        $bookSlug = slugify($book['book_title']);
        $paddedNum = str_pad($book['book_num'], 3, '0', STR_PAD_LEFT);
        $fileName = "{$paddedNum}-{$bookSlug}.md";
        
        $outputPath = $seriesLumpDir . '/' . $fileName;
        
        // Start building the master file content
        // Insert top-level YAML frontmatter for the aggregated book file.
        $masterContent = "---\n";
        $masterContent .= "title: \"{$book['book_title']}\"\n";
        $masterContent .= "series: \"{$seriesTitle}\"\n";
        $masterContent .= "---\n\n";
        
        // Heading 1: The Book Name
        $masterContent .= "# {$book['book_title']}\n\n";
        
        foreach ($book['chapters'] as $chapter) {
            $cleanChapTitle = strip_tags($chapter['chap_title']);
            echo "    [Chapter] Reading: {$cleanChapTitle}\n";
            
            // Heading 2: The Chapter Name 
            $masterContent .= "## " . $cleanChapTitle . "\n\n";
            
            foreach ($chapter['parts'] as $part) {
                // Point to the specific narrative directory using the manifest path
                $filePath = $narrativeDir . '/' . $part['file_path'];
                
                if (!file_exists($filePath)) {
                    echo "      -> [Warning] Missing file: {$part['file_path']}\n";
                    continue; // Skip gracefully if a file referenced in manifest is missing
                }
                
                echo "      -> [Part] Appending: {$part['file_path']}\n";
                
                // Ingest the individual part's markdown
                $partContent = file_get_contents($filePath);
                
                // Extract YAML Frontmatter from the individual part
                // We parse this manually to selectively render metadata in the final text.
                $title = '';
                $date = '';
                $time = '';
                $timezone = '';
                
                if (preg_match('/^---([\s\S]*?)---/', ltrim($partContent), $matches)) {
                    $frontmatter = $matches[1];
                    // Regex patterns designed to capture values with or without quotes
                    if (preg_match('/^title:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $title = trim($m[1]);
                    if (preg_match('/^date:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $date = trim($m[1]);
                    if (preg_match('/^time:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $time = trim($m[1]);
                    if (empty($time) && preg_match('/^start_time:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $time = trim($m[1]);
                    if (preg_match('/^timezone:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $timezone = trim($m[1]);
                }
                
                // Single source of truth for Title
                // Prefer the frontmatter title if available, otherwise fallback to manifest title.
                $partNum = $part['part_num'] ?? '';
                $partTitleDisplay = $title ? "Part {$partNum}: {$title}" : strip_tags($part['part_title']);
                
                // Heading 3: The Part Name 
                $masterContent .= "### " . $partTitleDisplay . "\n\n";
                
                // Format and append extracted temporal metadata as visible markdown text.
                if (!empty($date)) {
                    $datetimeStr = "**Date:** " . $date;
                    if (!empty($time)) {
                        $datetimeStr .= " at " . $time;
                    }
                    if (!empty($timezone)) {
                        $datetimeStr .= " " . $timezone;
                    }
                    $masterContent .= $datetimeStr . "\n\n";
                }
                
                // Regex to strip the individual YAML Frontmatter block
                // Prevents nested frontmatter blocks within the aggregated file.
                $partContent = preg_replace('/^---[\s\S]*?---\s*/', '', ltrim($partContent));
                
                // Append the cleaned narrative text
                $masterContent .= trim($partContent) . "\n\n";
            }
        }
        
        // Write the compiled string to the new file, overwriting if it exists
        // Output path is guaranteed correct due to directory provisioning above.
        echo "    [Write] Saving compiled file to: {$outputPath}\n";
        file_put_contents($outputPath, $masterContent);
        echo "    [Success] Finished lumping {$book['book_title']}\n\n";
    }
}

echo "========================================================\n";
echo "Lumping Complete! All series are packaged and ready.\n";
echo "========================================================\n";
?>