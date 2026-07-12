<?php
echo "Starting Frontmatter Migration...\n";

$manifestFile = __DIR__ . '/katie.json';
if (!file_exists($manifestFile)) {
    die("Error: katie.json not found in " . __DIR__ . "\n");
}

$katie = json_decode(file_get_contents($manifestFile), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error: Invalid JSON syntax in katie.json\n");
}

// Ensure we are working with the new wrapped structure
$books = isset($katie['books']) ? $katie['books'] : $katie;

$processedCount = 0;

foreach ($books as $book) {
    echo "\nProcessing: {$book['book_title']}\n";
    
    foreach ($book['chapters'] as $chapter) {
        // 1. PARSE THE CHAPTER DATE
        $rawChapTitle = strip_tags($chapter['chap_title']); // Removes <sup> tags
        $dateFormatted = "";
        
        // Match standard format: "Month DD, YYYY" or "Month DDth, YYYY"
        if (preg_match('/([a-zA-Z]+)\s+(\d{1,2})(?:st|nd|rd|th)?,\s+(\d{4})/i', $rawChapTitle, $m)) {
            $dateFormatted = date('Y-m-d', strtotime("{$m[1]} {$m[2]}, {$m[3]}"));
        } 
        // Match fallback format: "Month YYYY" (Missing Day)
        elseif (preg_match('/([a-zA-Z]+)\s+(\d{4})/i', $rawChapTitle, $m)) {
            $dateFormatted = date('Y-m', strtotime("{$m[1]} 1, {$m[2]}"));
        }

        foreach ($chapter['parts'] as $part) {
            $filePath = __DIR__ . '/' . $part['file_path'];
            
            if (!file_exists($filePath)) {
                echo "  [Skipping] File not found: {$part['file_path']}\n";
                continue;
            }

            // 2. PARSE THE PART TITLE, TIME, AND TIMEZONE
            $rawPartTitle = $part['part_title'];
            
            // Expected string: "Part X: Title Text – 5:05 PM to 6:00 PM (PST)"
            // The regex splits at either an en-dash (–) or a standard hyphen (-)
            if (preg_match('/^Part\s+\d+:\s+(.*?)\s+[–\-]\s+(.*)$/u', $rawPartTitle, $m)) {
                $cleanTitle = trim($m[1]);
                $timeSection = trim($m[2]);
            } else {
                // Fallback if the string formatting is unexpected
                $cleanTitle = preg_replace('/^Part\s+\d+:\s+/', '', $rawPartTitle);
                $timeSection = "";
            }

            // Extract and Normalize Timezone
            $tz = "ET"; // Default
            if (preg_match('/\(([A-Z]{3,4})\)/i', $timeSection, $tzMatch)) {
                $rawTz = strtoupper($tzMatch[1]);
                if (str_starts_with($rawTz, 'P')) $tz = 'PT';
                elseif (str_starts_with($rawTz, 'C')) $tz = 'CT';
                elseif (str_starts_with($rawTz, 'M')) $tz = 'MT';
                elseif (str_starts_with($rawTz, 'E')) $tz = 'ET';
                
                // Remove timezone string from the time section to parse the time cleanly
                $timeSection = trim(str_replace($tzMatch[0], '', $timeSection));
            }

            // Extract Start and End Times
            $startTime = "";
            $endTime = "";
            
            if (strpos(strtolower($timeSection), ' to ') !== false) {
                $times = explode(' to ', strtolower($timeSection));
                $startTime = date('H:i', strtotime(trim($times[0])));
                $endTime = date('H:i', strtotime(trim($times[1])));
            } elseif (!empty($timeSection)) {
                $startTime = date('H:i', strtotime($timeSection));
            }

            // 3. BUILD THE YAML FRONTMATTER
            $yaml = "---\n";
            $yaml .= "title: \"{$cleanTitle}\"\n";
            if ($dateFormatted) $yaml .= "date: {$dateFormatted}\n";
            if ($startTime) $yaml .= "start_time: \"{$startTime}\"\n";
            if ($endTime) $yaml .= "end_time: \"{$endTime}\"\n";
            $yaml .= "timezone: \"{$tz}\"\n";
            $yaml .= "pov: \"\"\n";
            $yaml .= "---\n\n";

            // 4. MODIFY THE MARKDOWN FILE
            $markdown = file_get_contents($filePath);
            
            // Safety check: Don't double-inject if script is run twice
            if (str_starts_with(trim($markdown), '---')) {
                echo "  [Skipping] Frontmatter already exists: {$part['file_path']}\n";
                continue;
            }

            // Regex to aggressively strip the # Heading at the top
            // Looks for "# Part X:" and removes that entire line and any trailing blank lines
            $markdown = preg_replace('/^#\s+Part\s+\d+:.*?(?:\r?\n)+/i', '', ltrim($markdown));

            // Write the file back with the YAML prepend
            file_put_contents($filePath, $yaml . $markdown);
            
            echo "  -> Injected Frontmatter: {$cleanTitle}\n";
            $processedCount++;
        }
    }
}

echo "\nMigration Complete! Processed {$processedCount} Markdown files.\n";
?>