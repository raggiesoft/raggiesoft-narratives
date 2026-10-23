<?php
/**
 * ============================================================================
 * ARCHITECTURE & MODULE OVERVIEW: lump_narratives.php
 * ============================================================================
 * Purpose:
 * This script aggregates fragmented Markdown narrative files (scenes) into 
 * consolidated novel-level Markdown files. It reads 'ovab.json' manifests 
 * to determine the correct structural titles.
 * 
 * Architectural Role:
 * Functions as a build/compile step for the narrative system. It takes the 
 * normalized source-of-truth files in `/books` and compiles them into a 
 * delivery or processing format in `/books-ai-lump`. This output is 
 * optimized for Pandoc EPUB/PDF compilation.
 * 
 * Key Components:
 * 1. Directory Management: Automatically cleans and provisions the target 
 *    `/books-ai-lump` directory to ensure idempotent runs.
 * 2. Manifest Driven: Relies exclusively on `ovab.json` for metadata.
 * 3. Content Merging: Extracts YAML frontmatter from individual scenes, 
 *    reformats relevant metadata into Markdown text, and concatenates 
 *    the main content under appropriate heading levels.
 * 
 * Maintenance Notes:
 * - The recursive directory deletion (`deleteDir`) is potent; ensure path 
 *   variables (`$lumpBaseDir`) are always strictly validated.
 * ============================================================================
 */

echo "Starting AI Context Lumper...\n";
echo "Validating directories...\n";

// Define our working directories based on the script's location
$baseDir = dirname(__DIR__); // Moves up to /raggiesoft-narratives
$booksBaseDir = $baseDir . '/books';
$lumpBaseDir = $baseDir . '/books-ai-lump';

if (!is_dir($booksBaseDir)) {
    die("Error: Base books directory not found at {$booksBaseDir}\n");
}

// Ensure the target base directory exists
if (!is_dir($lumpBaseDir)) {
    echo "Creating master output directory: /books-ai-lump/\n";
    mkdir($lumpBaseDir, 0755, true);
}

function slugify($string) {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string), '-'));
    return preg_replace('/-+/', '-', $slug);
}

// Scan for all narrative series folders inside the /books directory
$narrativeDirs = array_filter(glob($booksBaseDir . '/*'), 'is_dir');

if (empty($narrativeDirs)) {
    die("No narrative folders found in {$booksBaseDir}\n");
}

// Loop through each novel (e.g., /books/rachel)
foreach ($narrativeDirs as $narrativeDir) {
    $narrativeName = basename($narrativeDir); // e.g., 'rachel'
    $novelManifestFile = $narrativeDir . '/ovab.json';
    
    echo "\n========================================================\n";
    echo "Scanning Novel: {$narrativeName}\n";
    echo "========================================================\n";

    $seriesTitle = $narrativeName;
    if (file_exists($novelManifestFile)) {
        $novelMeta = json_decode(file_get_contents($novelManifestFile), true);
        if (isset($novelMeta['series_title'])) {
            $seriesTitle = $novelMeta['series_title'];
        } elseif (isset($novelMeta['title'])) {
            $seriesTitle = $novelMeta['title'];
        }
    }

    // Create the specific narrative folder inside books-ai-lump
    $seriesLumpDir = $lumpBaseDir . '/' . $narrativeName;
    if (!is_dir($seriesLumpDir)) {
        echo "  [Setup] Creating directory: /books-ai-lump/{$narrativeName}/\n";
        mkdir($seriesLumpDir, 0755, true);
    }

    $novelSlug = slugify($seriesTitle);
    $outputPath = $seriesLumpDir . '/' . $novelSlug . '-complete.md';
    
    $masterContent = "---\n";
    $masterContent .= "title: \"{$seriesTitle}\"\n";
    $masterContent .= "---\n\n";

    // Auto-discover Eras (bXXX directories)
    $eraDirs = glob($narrativeDir . '/e*', GLOB_ONLYDIR);
    sort($eraDirs);
    
    foreach ($eraDirs as $eraDir) {
        $eraName = basename($eraDir);
        $eraNum = intval(str_replace('e', '', $eraName));
        
        $eraTitle = "Era {$eraNum}";
        $eraManifest = $eraDir . '/ovab.json';
        if (file_exists($eraManifest)) {
            $meta = json_decode(file_get_contents($eraManifest), true);
            if (isset($meta['title'])) $eraTitle = $meta['title'];
        }
        
        echo "  Processing Era: {$eraTitle}...\n";
        
        // Heading 1: The Era Name
        $masterContent .= "# {$eraTitle}\n\n";
        
        // Auto-discover Chapters (cXXX directories)
        $chapterDirs = glob($eraDir . '/c*', GLOB_ONLYDIR);
        sort($chapterDirs);
        
        foreach ($chapterDirs as $chapDir) {
            $chapName = basename($chapDir);
            $chapNum = intval(str_replace('c', '', $chapName));
            
            $chapTitle = "Chapter {$chapNum}";
            $chapManifest = $chapDir . '/ovab.json';
            if (file_exists($chapManifest)) {
                $meta = json_decode(file_get_contents($chapManifest), true);
                if (isset($meta['title'])) $chapTitle = $meta['title'];
            }
            
            $cleanChapTitle = strip_tags($chapTitle);
            echo "    [Chapter] Reading: {$cleanChapTitle}\n";
            
            // Heading 2: The Chapter Name 
            $masterContent .= "## " . $cleanChapTitle . "\n\n";
            
            // Auto-discover Scenes (pXXX.md files)
            $sceneFiles = glob($chapDir . '/s*.md');
            sort($sceneFiles);
            
            foreach ($sceneFiles as $sceneFile) {
                $sceneName = basename($sceneFile);
                $sceneNum = intval(str_replace(['s', '.md'], '', $sceneName));
                
                $relPath = str_replace($baseDir . '/', '', $sceneFile);
                echo "      -> [Scene] Appending: {$relPath}\n";
                
                $partContent = file_get_contents($sceneFile);
                
                $title = '';
                $date = '';
                $time = '';
                $timezone = '';
                $sceneImage = '';
                $sceneImageAlt = '';
                
                if (preg_match('/^---([\s\S]*?)---/', ltrim($partContent), $matches)) {
                    $frontmatter = $matches[1];
                    if (preg_match('/^title:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $title = trim($m[1]);
                    if (preg_match('/^date:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $date = trim($m[1]);
                    if (preg_match('/^time:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $time = trim($m[1]);
                    if (empty($time) && preg_match('/^start_time:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $time = trim($m[1]);
                    if (preg_match('/^timezone:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $timezone = trim($m[1]);
                    if (preg_match('/^image:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $sceneImage = trim($m[1], "\"' \t");
                    if (preg_match('/^image_alt:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $sceneImageAlt = trim($m[1], "\"' \t");
                }
                
                $sceneTitleDisplay = $title ? "Scene {$sceneNum}: {$title}" : "Scene {$sceneNum}";
                
                // Heading 3: The Scene Name 
                $masterContent .= "### " . $sceneTitleDisplay . "\n\n";
                
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
                
                // Inject YAML frontmatter `image:` as a chapter/scene header plate for the Illustrated EPUB/PDF
                if ($sceneImage !== '') {
                    $normalizedSceneImg = str_replace('{{cms}}', '{{CDN}}', $sceneImage);
                    $normalizedSceneImg = preg_replace('/#.*$/', '', $normalizedSceneImg);
                    if (strpos($normalizedSceneImg, '{{CDN}}') !== 0 && strpos($normalizedSceneImg, 'http') !== 0) {
                        $cleanRel = ltrim($normalizedSceneImg, '/');
                        if (strpos($cleanRel, 'raggiesoft-books/') !== 0 && strpos($cleanRel, 'engine-room-records/') !== 0) {
                            $cleanRel = 'raggiesoft-books/images/scenes/' . $cleanRel;
                        }
                        $normalizedSceneImg = '{{CDN}}/' . $cleanRel;
                    }
                    $altDisplay = $sceneImageAlt !== '' ? $sceneImageAlt : $sceneTitleDisplay;
                    $masterContent .= "![{$altDisplay}]({$normalizedSceneImg})\n\n";
                }
                
                // Regex to strip the individual YAML Frontmatter block
                $partContent = preg_replace('/^---[\s\S]*?---\s*/', '', ltrim($partContent));
                
                // Append the cleaned narrative text
                $masterContent .= trim($partContent) . "\n\n";
            }
        }
    }
    
    // Only write to disk if the lump file doesn't exist or content has genuinely changed
    $contentChanged = true;
    if (file_exists($outputPath)) {
        if (md5_file($outputPath) === md5($masterContent)) {
            $contentChanged = false;
        }
    }
    
    if ($contentChanged) {
        echo "    [Write] Saving compiled novel to: {$outputPath}\n";
        file_put_contents($outputPath, $masterContent);
        echo "    [Success] Finished lumping novel {$seriesTitle}\n\n";
    } else {
        echo "    [Unchanged] Skipping write for {$seriesTitle} (content identical, preserving mtime)\n\n";
    }
}

echo "========================================================\n";
echo "Lumping Complete! All novels are packaged and ready.\n";
echo "========================================================\n";
?>