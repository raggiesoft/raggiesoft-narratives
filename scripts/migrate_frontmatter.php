<?php
/**
 * ============================================================================
 * READ-ALONG TEACHING OVERVIEW: migrate_frontmatter.php
 * ============================================================================
 * WHAT THIS FILE IS:
 *   A one-way CLI PHP migration and normalization script that reads legacy
 *   `katie.json` book manifests inside `raggiesoft-narratives/books/`, scrubs
 *   verbose prefixes and inline date/time suffixes out of JSON titles, and
 *   injects those dates, start times, and timezones into the YAML frontmatter
 *   (`--- ... ---`) at the top of each corresponding Markdown scene file.
 *
 * HOW IT IS RUN:
 *   Executed from the terminal via:
 *     `php raggiesoft-narratives/scripts/migrate_frontmatter.php`
 *   (Strictly keeps `<?php` on Line 1 for PHP CLI compatibility.)
 *
 * WHAT INPUTS IT CONSUMES:
 *   1. `../books/<series>/katie.json`: Legacy hierarchical JSON manifests
 *      organized as `Books -> Chapters -> Parts`, where titles originally
 *      baked dates and times into strings like:
 *        `"Chapter 1: First Day – Wednesday, August 20th, 2014"`
 *        `"Part 2: Boarding – 7:00 PM (Newfoundland Daylight Time)"`
 *   2. Individual Markdown scene files referenced by each part's `"file_path"`.
 *
 * WHAT OUTPUT IT PRODUCES:
 *   1. Updates each `.md` file's YAML frontmatter block with normalized
 *      `title`, `date` (`YYYY-MM-DD`), `time`, `timezone`, `start_time` (`HH:MM`
 *      24-hour clock), `end_time`, and `pov` keys (preserving any custom keys
 *      already present), while stripping duplicate `# Heading` lines from the body.
 *   2. Re-saves each `katie.json` with clean, prefix-free titles (only writing
 *      to disk when content actually changed so Git history stays clean).
 *
 * 100-LEVEL PHP CLI CONCEPTS TAUGHT IN THIS FILE:
 *   - `glob()` Pattern Matching: Discovering files across subdirectories.
 *   - Pass-by-Reference Loops (`foreach ($arr as &$item)`): Why the `&` symbol
 *     lets a loop edit elements inside the original array directly.
 *   - Regular Expressions (`preg_match` & `preg_replace`): Using capture groups
 *     `(...)` to split composite title strings into structured fields.
 *   - YAML Frontmatter Parsing & Idempotent File Writes (`$newContent !== $content`).
 * ============================================================================
 */

/* Resolve `../books` relative to this script's folder (`__DIR__`) into a canonical
 * absolute filesystem path (returns `false` if the directory doesn't exist). */
$booksDir = realpath(__DIR__ . '/../books');

/* Fail fast with a clear message if `../books/` is missing. */
if (!$booksDir || !is_dir($booksDir)) {
    die("Error: The directory ../books/ does not exist or cannot be resolved.\n");
}

/**
 * Helper: Converts human-written English date strings (such as
 * `"Wednesday, August 20th, 2014"`) into ISO-8601 `"YYYY-MM-DD"` format (`"2014-08-20"`).
 *
 * Step-by-step:
 *   1. If empty, return `'""'` (two literal quote characters representing an empty
 *      YAML string value when written into `date: ""`).
 *   2. `strip_tags()` removes any stray HTML like `<em>` or `<span>` from legacy JSON.
 *   3. `preg_replace('/(\d+)(st|nd|rd|th)/i', '$1', $clean)` strips English ordinal
 *      suffixes (`1st` -> `1`, `20th` -> `20`) so PHP's `strtotime()` parser never trips.
 *   4. `strtotime($clean)` converts the English date into a Unix epoch timestamp
 *      (seconds since Jan 1, 1970), and `date('Y-m-d', $timestamp)` formats it cleanly.
 *
 * @param string $dateStr Raw date substring extracted from a chapter title.
 * @return string ISO `YYYY-MM-DD` date string, or `'""'` if unparseable.
 */
function parseExtractedDate($dateStr) {
    if (!$dateStr) return '""';
    
    // Strip HTML tags just in case there is stray markup in the JSON
    $clean = strip_tags($dateStr);
    // Remove ordinal indicators (`st`, `nd`, `rd`, `th`) while keeping the digits (`$1`)
    $clean = preg_replace('/(\d+)(st|nd|rd|th)/i', '$1', $clean);
    
    // Attempt conversion to a Unix timestamp and format as YYYY-MM-DD
    $timestamp = strtotime($clean);
    if ($timestamp) {
        return date('Y-m-d', $timestamp);
    }
    
    // Default YAML empty-string fallback if parsing fails
    return '""'; 
}

// ============================================================================
// STEP 1: DISCOVER ALL `katie.json` MANIFESTS VIA `glob()`
// ============================================================================
// `glob('/path/to/books/*/katie.json')` acts just like wildcard expansion in a
// terminal shell: the `*` matches every immediate series folder inside `books/`
// that contains a `katie.json` file, returning an array of matching file paths.
$jsonFiles = glob($booksDir . '/*/katie.json');

if (empty($jsonFiles)) {
    die("No katie.json files found in {$booksDir}/*/\n");
}

echo "Found " . count($jsonFiles) . " JSON files. Processing...\n\n";

/* ============================================================================
 * STEP 2: TRAVERSE `JSON -> BOOKS -> CHAPTERS -> PARTS` & SYNC MARKDOWN FILES
 * ============================================================================ */
foreach ($jsonFiles as $jsonFile) {
    /* `dirname($jsonFile)` gets `/.../books/katie`, and `basename(...)` extracts `'katie'`. */
    $bookFolderName = basename(dirname($jsonFile));
    /* Read the entire JSON file into memory and decode into a nested PHP associative array. */
    $jsonData = json_decode(file_get_contents($jsonFile), true);
    
    /* Guard against malformed JSON so we never overwrite a file with `null`. */
    if (!$jsonData) {
        echo "Failed to decode JSON in {$bookFolderName}. Skipping...\n";
        continue;
    }
    
    /* 100-LEVEL PHP LESSON — PASS-BY-REFERENCE (`&$book`, `&$chapter`, `&$part`):
     * Normally in PHP, `foreach ($jsonData as $book)` gives you a *copy* of each
     * book array, so editing `$book['book_title']` wouldn't change `$jsonData`!
     * Prefixing `&` (`&$book`) creates a *reference* (alias) directly into `$jsonData`,
     * so every cleanup we make to `$book`, `$chapter`, and `$part` updates `$jsonData`
     * in place for Step 3 when we save the cleaned JSON back to disk. */
    foreach ($jsonData as &$book) {
        /* 2A. Scrub redundant `"Book 1: "` or `"Books: "` prefixes off `book_title`. */
        if (isset($book['book_title'])) {
            $book['book_title'] = trim(preg_replace('/^Book(?:s|\s*[a-zA-Z0-9]*)*:\s*/i', '', $book['book_title']));
        }
        
        if (isset($book['chapters'])) {
            foreach ($book['chapters'] as &$chapter) {
                $chapterDate = '""';
                
                if (isset($chapter['chap_title'])) {
                    /* 2B. Strip `"Chapter 1: "` / `"Chapter IV: "` prefixes off `chap_title`. */
                    $chapter['chap_title'] = trim(preg_replace('/^Chapter\s*[a-zA-Z0-9]*:\s*/i', '', $chapter['chap_title']));
                    
                    /* 2C. Split trailing `" – Weekday, Month Day, Year"` off `chap_title`:
                     *   - Capture group 1 `(.*?)`: The clean chapter title before the en-dash/hyphen.
                     *   - `(?:–|-)`: Non-capturing group matching either an en-dash `–` or ASCII hyphen `-`.
                     *   - Capture group 2 `([A-Za-z]+, ...)`: The full English calendar date at the end (`$`). */
                    if (preg_match('/^(.*?)\s*(?:–|-)\s*([A-Za-z]+,\s*[A-Za-z]+\s*\d+(?:st|nd|rd|th)?(?:,\s*\d{4}))$/', $chapter['chap_title'], $chapMatches)) {
                        $chapter['chap_title'] = trim($chapMatches[1]);     // Keep clean title only
                        $chapterDate = parseExtractedDate($chapMatches[2]); // Convert date to YYYY-MM-DD
                    }
                }
                
                if (isset($chapter['parts'])) {
                    foreach ($chapter['parts'] as &$part) {
                        if (isset($part['part_title'])) {
                            /* 2D. Strip `"Part 1: "` prefix off `part_title`. */
                            $rawTitle = trim(preg_replace('/^Part\s*[a-zA-Z0-9]*:\s*/i', '', $part['part_title']));
                            
                            $startTime = '""';
                            $timezone = '""';
                            
                            /* 2E. Extract trailing clock time & optional `(Timezone)` from `part_title`:
                             * Example input: `"Arrival – 7:00 PM (Newfoundland Daylight Time)"`
                             *   - `$partMatches[1]`: `"Arrival"`
                             *   - `$partMatches[2]`: `"7:00 PM"` -> converted via `date('H:i', ...)` to `"19:00"`
                             *   - `$partMatches[3]`: `"Newfoundland Daylight Time"` (optional parenthetical) */
                            if (preg_match('/^(.*?)\s*(?:–|-)\s*(\d{1,2}:\d{2}\s*(?:AM|PM))(?:\s*\((.*?)\))?$/i', $rawTitle, $partMatches)) {
                                $rawTitle = trim($partMatches[1]);
                                
                                // Convert 12-hour AM/PM clock string into 24-hour `HH:MM` format
                                $timeTimestamp = strtotime($partMatches[2]);
                                if ($timeTimestamp) {
                                    $startTime = '"' . date('H:i', $timeTimestamp) . '"';
                                }
                                
                                // Escape and quote the optional timezone label if present
                                if (!empty($partMatches[3])) {
                                    $timezone = '"' . addslashes(trim($partMatches[3])) . '"';
                                }
                            }
                            
                            /* Store the scrubbed title back into the JSON array reference. */
                            $part['part_title'] = $rawTitle;
                            
                            /* ================================================
                             * 2F. LOCATE & UPDATE THE TARGET MARKDOWN `.md` FILE
                             * ================================================ */
                            if (isset($part['file_path'])) {
                                /* Normalize any Windows `\` slashes to `/` and join onto the series folder. */
                                $mdFilePath = dirname($jsonFile) . '/' . ltrim(str_replace('\\', '/', $part['file_path']), '/');
                                
                                if (file_exists($mdFilePath)) {
                                    $content = file_get_contents($mdFilePath);
                                    
                                    /* Split existing YAML Frontmatter (`--- ... ---` at line 1) from prose body:
                                     * - `^---\s*\n`: Opening `---` fence at the very start of the file.
                                     * - `(.*?)`: Lazy capture group 1 grabbing YAML lines up to the FIRST closing `---`.
                                     * - `(.*)$`: Capture group 2 grabbing the entire remaining Markdown story body.
                                     * - Trailing `/s` ("single-line / dotall" modifier): Makes `.` match `\n` newlines! */
                                    $hasFrontmatter = preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $matches);
                                    
                                    $existingFields = [];
                                    $body = $content;
                                    
                                    /* Parse existing `key: value` YAML lines so we preserve custom keys
                                     * (like `pov:`, `audio:`, `image:`, or `theme:`) already on the file. */
                                    if ($hasFrontmatter) {
                                        $lines = explode("\n", trim($matches[1]));
                                        $body = $matches[2];
                                        foreach ($lines as $line) {
                                            if (preg_match('/^([a-zA-Z0-9_-]+)\s*:\s*(.*)$/', $line, $fieldMatches)) {
                                                $existingFields[trim($fieldMatches[1])] = trim($fieldMatches[2]);
                                            }
                                        }
                                    }
                                    
                                    /* Strip any legacy top-of-body `# Heading` line so the reader UI doesn't
                                     * render the title twice (once from YAML `title:` and once from `# Title`). */
                                    $body = ltrim($body);
                                    $body = preg_replace('/^#\s+[^\n]+/', '', $body);
                                    $body = ltrim($body);
                                    
                                    /* Overlay the newly scrubbed JSON metadata onto `$existingFields`. */
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
                                    
                                    /* Guarantee every scene file has all 7 canonical frontmatter keys
                                     * in a deterministic order at the top of the YAML block. */
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
                                    
                                    /* Rebuild the `--- ... ---` YAML frontmatter block:
                                     *   1. Emit the 7 standard keys in canonical order first.
                                     *   2. Emit any extra custom keys (`image`, `audio`, `location`, etc.) after. */
                                    $newFrontmatter = "---\n";
                                    
                                    foreach (array_keys($defaults) as $key) {
                                        $newFrontmatter .= "{$key}: {$existingFields[$key]}\n";
                                    }
                                    
                                    foreach ($existingFields as $k => $v) {
                                        if (!array_key_exists($k, $defaults)) {
                                            $newFrontmatter .= "{$k}: {$v}\n";
                                        }
                                    }
                                    $newFrontmatter .= "---\n";
                                    
                                    $newContent = $newFrontmatter . $body;
                                    
                                    /* IDEMPOTENT WRITE CHECK:
                                     * Only call `file_put_contents()` if `$newContent` actually differs
                                     * from `$content`. That preserves filesystem modification timestamps
                                     * (`mtime`) when re-running the script on already-migrated books! */
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
    
    /* ========================================================================
     * STEP 3: SAVE SCRUBBED `katie.json` BACK TO DISK (IF CHANGED)
     * ========================================================================
     * Flags explained:
     *   - `JSON_PRETTY_PRINT`: Multi-line indented formatting.
     *   - `JSON_UNESCAPED_UNICODE`: Keeps accented characters (`Zoë`, `–`) readable instead of `\u00eb`.
     *   - `JSON_UNESCAPED_SLASHES`: Keeps `"e001/c001/p001.md"` readable instead of `"e001\/c001\/p001.md"`.
     */
    $newJsonContent = json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $originalJsonContent = file_get_contents($jsonFile);
    
    if ($newJsonContent !== $originalJsonContent) {
        file_put_contents($jsonFile, $newJsonContent);
        echo "Cleaned JSON: " . ltrim(str_replace($booksDir, '', $jsonFile), '/\\') . "\n";
    }
}

echo "\nCleanup and migration complete.\n";

?>
