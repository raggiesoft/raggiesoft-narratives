<?php
/**
 * ============================================================================
 * ARCHITECTURE & MODULE OVERVIEW: migrate_frontmatter.php
 * ============================================================================
 * Purpose:
 * This script is responsible for traversing the 'books' directory to locate
 * 'katie.json' manifest files, extracting temporal and structural data from 
 * the JSON hierarchies (Books -> Chapters -> Parts), and applying that data 
 * into the YAML frontmatter of the corresponding Markdown files.
 * 
 * Architectural Role:
 * Acts as a one-way migration and synchronization tool bridging legacy JSON 
 * metadata with modern Markdown YAML frontmatter. It is intended to standardize 
 * file structures for future processing, ensuring each Markdown file has a 
 * consistent set of metadata fields.
 * 
 * Key Components:
 * 1. JSON Parsing & Traversal: Iterates through series, books, chapters, parts.
 * 2. Regex Extraction: Scrubs prefixes (e.g., "Chapter 1:") and extracts dates,
 *    times, and timezones from raw titles.
 * 3. File I/O: Reads, updates, and writes both Markdown files and JSON files 
 *    with the newly scrubbed and normalized data.
 * 
 * Maintenance Notes:
 * - If the JSON structure of katie.json changes, the nested foreach loops 
 *   will need to be updated.
 * - The date/time extraction relies heavily on specific string formats 
 *   (e.g., " – Wednesday, August 20th, 2014"). Changes in authoring 
 *   conventions will require updates to the regular expressions.
 * ============================================================================
 */

$booksDir = realpath(__DIR__ . '/../books');

// Ensure the target directory exists before proceeding to prevent path errors.
if (!$booksDir || !is_dir($booksDir)) {
    die("Error: The directory ../books/ does not exist or cannot be resolved.\n");
}

/**
 * Strips HTML and ordinals (st, nd, rd, th) to help PHP's strtotime()
 * reliably convert complex date strings into standard YYYY-MM-DD.
 * 
 * @param string $dateStr The raw date string extracted from the chapter title.
 * @return string The normalized YYYY-MM-DD date or an empty string representation.
 */
function parseExtractedDate($dateStr) {
    if (!$dateStr) return '""';
    
    // Strip HTML tags just in case there is stray markup in the JSON
    $clean = strip_tags($dateStr);
    // Remove ordinal indicators which can sometimes confuse strtotime()
    $clean = preg_replace('/(\d+)(st|nd|rd|th)/i', '$1', $clean);
    
    // Attempt conversion to a Unix timestamp
    $timestamp = strtotime($clean);
    if ($timestamp) {
        return date('Y-m-d', $timestamp);
    }
    
    // Default fallback if parsing fails
    return '""'; 
}

// 1. Locate all katie.json files
// Using glob to recursively find manifest files within the immediate subdirectories.
$jsonFiles = glob($booksDir . '/*/katie.json');

if (empty($jsonFiles)) {
    die("No katie.json files found in {$booksDir}/*/\n");
}

echo "Found " . count($jsonFiles) . " JSON files. Processing...\n\n";

// Process each found JSON manifest sequentially.
foreach ($jsonFiles as $jsonFile) {
    $bookFolderName = basename(dirname($jsonFile));
    $jsonData = json_decode(file_get_contents($jsonFile), true);
    
    // Skip on decode failure to prevent data corruption.
    if (!$jsonData) {
        echo "Failed to decode JSON in {$bookFolderName}. Skipping...\n";
        continue;
    }
    
    // Iterate by reference (&$) so we can modify the JSON array directly
    // This allows us to scrub the titles in memory before saving back to disk.
    foreach ($jsonData as &$book) {
        // Clean Book Title
        // Removes generic prefixes like "Book 1:" to standardize titles.
        if (isset($book['book_title'])) {
            $book['book_title'] = trim(preg_replace('/^Book(?:s|\s*[a-zA-Z0-9]*)*:\s*/i', '', $book['book_title']));
        }
        
        if (isset($book['chapters'])) {
            // Iterate over chapters by reference.
            foreach ($book['chapters'] as &$chapter) {
                $chapterDate = '""';
                
                if (isset($chapter['chap_title'])) {
                    // Clean Chapter Prefix (e.g., "Chapter 1:" or "Chapter X:")
                    $chapter['chap_title'] = trim(preg_replace('/^Chapter\s*[a-zA-Z0-9]*:\s*/i', '', $chapter['chap_title']));
                    
                    // Extract Date from Chapter Title (e.g., " – Wednesday, August 20th, 2014")
                    // This regex splits the actual title from the appended date string.
                    if (preg_match('/^(.*?)\s*(?:–|-)\s*([A-Za-z]+,\s*[A-Za-z]+\s*\d+(?:st|nd|rd|th)?(?:,\s*\d{4}))$/', $chapter['chap_title'], $chapMatches)) {
                        $chapter['chap_title'] = trim($chapMatches[1]); // The core chapter title
                        $chapterDate = parseExtractedDate($chapMatches[2]); // The parsed date
                    }
                }
                
                if (isset($chapter['parts'])) {
                    // Iterate over parts by reference.
                    foreach ($chapter['parts'] as &$part) {
                        if (isset($part['part_title'])) {
                            // Clean Part Prefix (e.g., "Part 1:" or "Part X:")
                            $rawTitle = trim(preg_replace('/^Part\s*[a-zA-Z0-9]*:\s*/i', '', $part['part_title']));
                            
                            $startTime = '""';
                            $timezone = '""';
                            
                            // Extract Time and Optional Timezone (e.g., " – 10:00 AM" or " – 7:00 PM (Newfoundland Daylight Time)")
                            if (preg_match('/^(.*?)\s*(?:–|-)\s*(\d{1,2}:\d{2}\s*(?:AM|PM))(?:\s*\((.*?)\))?$/i', $rawTitle, $partMatches)) {
                                $rawTitle = trim($partMatches[1]);
                                
                                // Convert 12-hour AM/PM to 24-hour format
                                $timeTimestamp = strtotime($partMatches[2]);
                                if ($timeTimestamp) {
                                    $startTime = '"' . date('H:i', $timeTimestamp) . '"';
                                }
                                
                                // Capture parenthetical timezone if it exists
                                if (!empty($partMatches[3])) {
                                    $timezone = '"' . addslashes(trim($partMatches[3])) . '"';
                                }
                            }
                            
                            // Update the JSON object with the scrubbed title
                            $part['part_title'] = $rawTitle;
                            
                            // 5. Locate and Update the Markdown File
                            // Resolve the relative path stored in JSON to an absolute file path.
                            if (isset($part['file_path'])) {
                                $mdFilePath = dirname($jsonFile) . '/' . ltrim(str_replace('\\', '/', $part['file_path']), '/');
                                
                                if (file_exists($mdFilePath)) {
                                    $content = file_get_contents($mdFilePath);
                                    
                                    // Separate existing frontmatter from the body using regex.
                                    // Matches standard YAML block bounded by ---.
                                    $hasFrontmatter = preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $matches);
                                    
                                    $existingFields = [];
                                    $body = $content;
                                    
                                    // Parse existing frontmatter into a key-value array.
                                    if ($hasFrontmatter) {
                                        $lines = explode("\n", trim($matches[1]));
                                        $body = $matches[2];
                                        foreach ($lines as $line) {
                                            if (preg_match('/^([a-zA-Z0-9_-]+)\s*:\s*(.*)$/', $line, $fieldMatches)) {
                                                $existingFields[trim($fieldMatches[1])] = trim($fieldMatches[2]);
                                            }
                                        }
                                    }
                                    
                                    // Clean body content
                                    // Removes leading whitespace and potential duplicate H1 headings.
                                    $body = ltrim($body);
                                    $body = preg_replace('/^#\s+[^\n]+/', '', $body);
                                    $body = ltrim($body);
                                    
                                    // Apply Extracted Data from JSON to the frontmatter array.
                                    $existingFields['title'] = '"' . addslashes($rawTitle) . '"';
                                    
                                    if ($chapterDate !== '""') {
                                        $existingFields['date'] = $chapterDate;
                                    }
                                    if ($startTime !== '""') {
                                        $existingFields['start_time'] = $startTime;
                                    }
                                    if ($timezone !== '""') {
                                        $existingFields['timezone'] = $timezone;
                                    }
                                    
                                    // Enforce Required Frontmatter Structure & Defaults
                                    // Ensures a uniform schema across all Markdown files.
                                    $defaults = [
                                        'title'      => '""',
                                        'date'       => '""',
                                        'time'       => '""',
                                        'timezone'   => '""',
                                        'start_time' => '""',
                                        'end_time'   => '""',
                                        'pov'        => '""'
                                    ];
                                    
                                    // Populate defaults where keys are missing.
                                    foreach ($defaults as $k => $v) {
                                        if (!isset($existingFields[$k])) {
                                            $existingFields[$k] = $v;
                                        }
                                    }
                                    
                                    // Construct the new file content with updated YAML block.
                                    $newFrontmatter = "---\n";
                                    
                                    // Write default keys first for consistent ordering.
                                    foreach (array_keys($defaults) as $key) {
                                        $newFrontmatter .= "{$key}: {$existingFields[$key]}\n";
                                    }
                                    
                                    // Append any existing custom fields not in the defaults array to preserve data.
                                    foreach ($existingFields as $k => $v) {
                                        if (!array_key_exists($k, $defaults)) {
                                            $newFrontmatter .= "{$k}: {$v}\n";
                                        }
                                    }
                                    $newFrontmatter .= "---\n";
                                    
                                    $newContent = $newFrontmatter . $body;
                                    
                                    // Strictly write to disk if changes occurred to minimize unnecessary I/O.
                                    if ($newContent !== $content) {
                                        file_put_contents($mdFilePath, $newContent);
                                        echo "Updated MD: " . ltrim(str_replace($booksDir, '', $mdFilePath), '/\\') . "\n";
                                    }
                                } else {
                                    echo "Warning: Markdown file missing at {$mdFilePath}\n";
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Save the newly scrubbed JSON back to disk
    // Use PRETTY_PRINT to maintain human readability.
    $newJsonContent = json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $originalJsonContent = file_get_contents($jsonFile);
    
    // Only perform the write if there is a substantive change to the JSON.
    if ($newJsonContent !== $originalJsonContent) {
        file_put_contents($jsonFile, $newJsonContent);
        echo "Cleaned JSON: " . ltrim(str_replace($booksDir, '', $jsonFile), '/\\') . "\n";
    }
}

echo "\nCleanup and migration complete.\n";

?>