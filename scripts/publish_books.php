<?php
echo "Starting Stardust Engine Book Publisher...\n";
echo "Resolving server paths...\n";

// 1. Resolve Dynamic Paths (Assuming script is in /raggiesoft-servers/raggiesoft-narratives/scripts)
$serverRoot = dirname(__DIR__, 2); // Moves up twice to reach /raggiesoft-servers

$sourceBooksDir = $serverRoot . '/raggiesoft-narratives/books';
$assetDestDir   = $serverRoot . '/raggiesoft-assets/raggiesoft-books/books';
$routesDestDir  = $serverRoot . '/raggiesoft-hub/data/routes/raggiesoft-books';

// Validate source
if (!is_dir($sourceBooksDir)) {
    die("Error: Source directory not found at {$sourceBooksDir}\n");
}

// Ensure destination directories exist
if (!is_dir($assetDestDir)) mkdir($assetDestDir, 0755, true);
if (!is_dir($routesDestDir)) mkdir($routesDestDir, 0755, true);

// Helper function: Bulletproof Recursive Directory Wipe
function rrmdir($dir) {
    if (is_dir($dir)) {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            if (is_dir($path)) {
                rrmdir($path);
            } else {
                // Force file to be writable before deleting (fixes read-only errors)
                @chmod($path, 0777); 
                @unlink($path);
            }
        }
        
        // Force directory to be writable
        @chmod($dir, 0777);
        
        // Lock mitigation: Try to remove. If the OS denies it, pause for 50 milliseconds and try once more.
        if (!@rmdir($dir)) {
            usleep(50000); 
            @rmdir($dir);
        }
    }
}

// Helper function: Recursive Directory Copy
function rcopy($src, $dst) {
    if (is_dir($src)) {
        if (!is_dir($dst)) mkdir($dst, 0755, true);
        $files = array_diff(scandir($src), ['.', '..']);
        foreach ($files as $file) {
            rcopy("$src/$file", "$dst/$file");
        }
    } else if (file_exists($src)) {
        copy($src, $dst);
    }
}

// Helper function: Create URL Slugs
function slugify($string) {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string), '-'));
    return preg_replace('/-+/', '-', $slug);
}

// 2. Scan the source narratives
$narrativeDirs = array_filter(glob($sourceBooksDir . '/*'), 'is_dir');

if (empty($narrativeDirs)) {
    die("No narrative folders found in {$sourceBooksDir}\n");
}

foreach ($narrativeDirs as $narrativeDir) {
    $narrativeName = basename($narrativeDir); // e.g., 'rachel'
    $manifestFile = $narrativeDir . '/katie.json';
    
    echo "\n========================================================\n";
    echo "Publishing Series: {$narrativeName}\n";
    echo "========================================================\n";

    if (!file_exists($manifestFile)) {
        echo "[Skipping] No katie.json found in /books/{$narrativeName}/\n";
        continue;
    }

    // --- STEP A: DESTRUCTIVE ASSET SYNC ---
    $targetAssetDir = $assetDestDir . '/' . $narrativeName;
    
    if (is_dir($targetAssetDir)) {
        echo "  [Assets] Wiping existing CDN directory: {$targetAssetDir}\n";
        rrmdir($targetAssetDir);
    }
    
    echo "  [Assets] Copying Markdown, cover art, and katie.json to CDN...\n";
    rcopy($narrativeDir, $targetAssetDir);
    echo "  [Assets] Sync complete.\n";

    // --- STEP B: PARSE MANIFEST FOR ROUTES ---
    $katie = json_decode(file_get_contents($manifestFile), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "  [Error] Invalid JSON syntax in katie.json. Skipping route generation.\n";
        continue;
    }

    $seriesTitle = $katie['series_title'] ?? $narrativeName;
    $seriesSlug = slugify($seriesTitle);
    $books = $katie['books'] ?? $katie;

    echo "  [Routes] Generating Stardust Engine Route JSON for '{$seriesTitle}'...\n";

    // Build the Route JSON Array
    $routeData = [];
    
    // The Common Block
    $routeData['common'] = [
        "site" => "raggiesoft-books",
        "theme" => "",
        "siteName" => $seriesTitle,
        "showSidebar" => true,
        "sidebar" => "raggiesoft-books/sidebar-book",
        "headerMenu" => "raggiesoft-books/header-books",
        "footer" => "raggiesoft-books/footer-books"
    ];

    // The Series Overview Route
    $overviewUrl = "/raggiesoft-books/books/{$seriesSlug}";
    $routeData[$overviewUrl] = [
        "view" => "pages/raggiesoft-books/books/overview",
        "title" => "{$seriesTitle} - Library",
        "theme" => ""
    ];

    // Build individual Part Routes
    foreach ($books as $book) {
        foreach ($book['chapters'] as $chapter) {
            foreach ($chapter['parts'] as $part) {
                // Strip the .md extension for a clean URL
                // e.g., b001/c001/p001.md -> b001/c001/p001
                $cleanPath = preg_replace('/\.md$/i', '', $part['file_path']);
                
                $routeUrl = "/raggiesoft-books/books/{$seriesSlug}/{$cleanPath}";
                $cleanTitle = strip_tags($part['part_title']);
                
                $routeData[$routeUrl] = [
                    "view" => "pages/raggiesoft-books/books/viewer",
                    "title" => $cleanTitle,
                    "theme" => ""
                ];
            }
        }
    }

    // --- STEP C: WRITE ROUTE JSON ---
    $routeJsonFile = $routesDestDir . '/' . $seriesSlug . '.json';
    
    // Write the JSON payload beautifully formatted
    file_put_contents(
        $routeJsonFile, 
        json_encode($routeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    
    echo "  [Routes] Saved to: /data/routes/raggiesoft-books/{$seriesSlug}.json\n";
}

echo "========================================================\n";
echo "Publishing Complete! CDN updated and Stardust Routes mapped.\n";
echo "========================================================\n";
?>