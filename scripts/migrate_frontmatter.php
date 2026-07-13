<?php

$booksDir = realpath(__DIR__ . '/../books');

if (!$booksDir || !is_dir($booksDir)) {
    die("Error: The directory ../books/ does not exist or cannot be resolved.\n");
}

// 1. Recursively find all .md files
$directoryIterator = new RecursiveDirectoryIterator($booksDir, RecursiveDirectoryIterator::SKIP_DOTS);
$iterator = new RecursiveIteratorIterator($directoryIterator);

$files = [];
foreach ($iterator as $fileInfo) {
    if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'md') {
        $files[] = $fileInfo->getPathname();
    }
}

if (empty($files)) {
    die("No markdown files found recursively in {$booksDir}\n");
}

echo "Found " . count($files) . " markdown files. Processing...\n\n";

foreach ($files as $file) {
    $content = file_get_contents($file);
    
    // 2. Separate existing frontmatter from the body
    $hasFrontmatter = preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $matches);
    
    $existingFields = [];
    $body = $content;

    if ($hasFrontmatter) {
        $frontmatterString = $matches[1];
        $body = $matches[2];
        
        // Parse existing YAML into an associative array
        $lines = explode("\n", trim($frontmatterString));
        foreach ($lines as $line) {
            if (preg_match('/^([a-zA-Z0-9_-]+)\s*:\s*(.*)$/', $line, $fieldMatches)) {
                $existingFields[trim($fieldMatches[1])] = trim($fieldMatches[2]);
            }
        }
    }

    // 3. EXTRACT DATA USING YOUR REGEX
    // Insert your existing regex logic here to parse the $body or basename($file).
    $extractedTitle = ""; 
    $extractedDate = "";  
    $extractedTime = "";  
    $extractedTz = "";    

    /* 
     * Example Regex implementation:
     * if (preg_match('/# (.*)/', $body, $titleMatch)) { $extractedTitle = $titleMatch[1]; }
     * if (preg_match('/Date: (.*)/', $body, $dateMatch)) { $extractedDate = $dateMatch[1]; }
     */

    // 4. Define the desired fields. Fallback to empty quotes "" if regex missed them.
    $targetFields = [
        'title'    => $extractedTitle !== "" ? '"' . addslashes($extractedTitle) . '"' : '""',
        'date'     => $extractedDate !== "" ? '"' . $extractedDate . '"' : '""',
        'time'     => $extractedTime !== "" ? '"' . $extractedTime . '"' : '""',
        'timezone' => $extractedTz !== "" ? '"' . $extractedTz . '"' : '""'
    ];

    $requiresUpdate = false;
    
    // 5. Merge logic: Keep existing fields, append missing ones
    foreach ($targetFields as $key => $value) {
        if (!array_key_exists($key, $existingFields)) {
            $existingFields[$key] = $value;
            $requiresUpdate = true;
        }
    }

    if (!$hasFrontmatter) {
        $requiresUpdate = true;
    }

    // 6. Write back to the file if changes were made
    if ($requiresUpdate) {
        $newFrontmatter = "---\n";
        foreach ($existingFields as $k => $v) {
            $newFrontmatter .= "$k: $v\n";
        }
        $newFrontmatter .= "---\n";
        
        $newContent = $newFrontmatter . $body;
        file_put_contents($file, $newContent);
        
        // Output clean relative path for terminal readability
        $relativePath = str_replace($booksDir, '', $file);
        echo "Updated: {$relativePath}\n";
    } else {
        $relativePath = str_replace($booksDir, '', $file);
        echo "Skipped (Already complete): {$relativePath}\n";
    }
}

echo "\nFrontmatter migration complete.\n";

?>