<?php

$booksDir = realpath(__DIR__ . '/../books');

if (!$booksDir || !is_dir($booksDir)) {
    die("Error: The directory ../books/ does not exist or cannot be resolved.\n");
}

/**
 * Strips HTML and ordinals (st, nd, rd, th) to help PHP's strtotime()
 * reliably convert complex date strings into standard YYYY-MM-DD.
 */
function parseExtractedDate($dateStr) {
    if (!$dateStr) return '""';
    
    $clean = strip_tags($dateStr);
    $clean = preg_replace('/(\d+)(st|nd|rd|th)/i', '$1', $clean);
    
    $timestamp = strtotime($clean);
    if ($timestamp) {
        return date('Y-m-d', $timestamp);
    }
    
    return '""'; 
}

// 1. Locate all katie.json files
$jsonFiles = glob($booksDir . '/*/katie.json');

if (empty($jsonFiles)) {
    die("No katie.json files found in {$booksDir}/*/\n");
}

echo "Found " . count($jsonFiles) . " JSON files. Processing...\n\n";

foreach ($jsonFiles as $jsonFile) {
    $bookFolderName = basename(dirname($jsonFile));
    $jsonData = json_decode(file_get_contents($jsonFile), true);
    
    if (!$jsonData) {
        echo "Failed to decode JSON in {$bookFolderName}. Skipping...\n";
        continue;
    }
    
    // Iterate by reference (&$) so we can modify the JSON array directly
    foreach ($jsonData as &$book) {
        // Clean Book Title
        if (isset($book['book_title'])) {
            $book['book_title'] = trim(preg_replace('/^Book(?:s|\s*[a-zA-Z0-9]*)*:\s*/i', '', $book['book_title']));
        }
        
        if (isset($book['chapters'])) {
            foreach ($book['chapters'] as &$chapter) {
                $chapterDate = '""';
                
                if (isset($chapter['chap_title'])) {
                    // Clean Chapter Prefix (e.g., "Chapter 1:" or "Chapter X:")
                    $chapter['chap_title'] = trim(preg_replace('/^Chapter\s*[a-zA-Z0-9]*:\s*/i', '', $chapter['chap_title']));
                    
                    // Extract Date from Chapter Title (e.g., " – Wednesday, August 20th, 2014")[cite: 6]
                    if (preg_match('/^(.*?)\s*(?:–|-)\s*([A-Za-z]+,\s*[A-Za-z]+\s*\d+(?:st|nd|rd|th)?(?:,\s*\d{4}))$/', $chapter['chap_title'], $chapMatches)) {
                        $chapter['chap_title'] = trim($chapMatches[1]);
                        $chapterDate = parseExtractedDate($chapMatches[2]);
                    }
                }
                
                if (isset($chapter['parts'])) {
                    foreach ($chapter['parts'] as &$part) {
                        if (isset($part['part_title'])) {
                            // Clean Part Prefix (e.g., "Part 1:" or "Part X:")[cite: 6]
                            $rawTitle = trim(preg_replace('/^Part\s*[a-zA-Z0-9]*:\s*/i', '', $part['part_title']));
                            
                            $startTime = '""';
                            $timezone = '""';
                            
                            // Extract Time and Optional Timezone (e.g., " – 10:00 AM" or " – 7:00 PM (Newfoundland Daylight Time)")[cite: 6]
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
                            if (isset($part['file_path'])) {
                                $mdFilePath = dirname($jsonFile) . '/' . ltrim(str_replace('\\', '/', $part['file_path']), '/');
                                
                                if (file_exists($mdFilePath)) {
                                    $content = file_get_contents($mdFilePath);
                                    
                                    // Separate existing frontmatter from the body
                                    $hasFrontmatter = preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $matches);
                                    
                                    $existingFields = [];
                                    $body = $content;
                                    
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
                                    $body = ltrim($body);
                                    $body = preg_replace('/^#\s+[^\n]+/', '', $body);
                                    $body = ltrim($body);
                                    
                                    // Apply Extracted Data
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
                                    $defaults = [
                                        'title'      => '""',
                                        'date'       => '""',
                                        'time'       => '""',
                                        'timezone'   => '""',
                                        'start_time' => '""',
                                        'end_time'   => '""',
                                        'pov'        => '""'
                                    ];
                                    
                                    foreach ($defaults as $k => $v) {
                                        if (!isset($existingFields[$k])) {
                                            $existingFields[$k] = $v;
                                        }
                                    }
                                    
                                    // Construct the new file content
                                    $newFrontmatter = "---\n";
                                    foreach (array_keys($defaults) as $key) {
                                        $newFrontmatter .= "{$key}: {$existingFields[$key]}\n";
                                    }
                                    
                                    // Append any existing custom fields not in the defaults array
                                    foreach ($existingFields as $k => $v) {
                                        if (!array_key_exists($k, $defaults)) {
                                            $newFrontmatter .= "{$k}: {$v}\n";
                                        }
                                    }
                                    $newFrontmatter .= "---\n";
                                    
                                    $newContent = $newFrontmatter . $body;
                                    
                                    // Strictly write to disk if changes occurred
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
    $newJsonContent = json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $originalJsonContent = file_get_contents($jsonFile);
    
    if ($newJsonContent !== $originalJsonContent) {
        file_put_contents($jsonFile, $newJsonContent);
        echo "Cleaned JSON: " . ltrim(str_replace($booksDir, '', $jsonFile), '/\\') . "\n";
    }
}

echo "\nCleanup and migration complete.\n";

?>