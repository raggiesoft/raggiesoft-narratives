<?php
echo "Starting Stardust Engine Book Publisher...\n";
echo "Resolving server paths...\n";

$serverRoot = dirname(__DIR__, 2);
$sourceBooksDir = $serverRoot . '/raggiesoft-narratives/books';
$assetDestDir   = $serverRoot . '/raggiesoft-assets/raggiesoft-books/books';
$routesDestDir  = $serverRoot . '/raggiesoft-hub/data/routes/raggiesoft-books/books';

if (!is_dir($sourceBooksDir)) {
    die("Error: Source directory not found at {$sourceBooksDir}\n");
}
if (!is_dir($assetDestDir)) mkdir($assetDestDir, 0755, true);
if (!is_dir($routesDestDir)) mkdir($routesDestDir, 0755, true);

function rrmdir($dir) {
    if (is_dir($dir)) {
        $scanned = @scandir($dir);
        if ($scanned !== false) {
            $files = array_diff($scanned, ['.', '..']);
            foreach ($files as $file) {
                $path = "$dir/$file";
                if (is_dir($path)) {
                    rrmdir($path);
                } else {
                    @chmod($path, 0777); 
                    @unlink($path);
                }
            }
        }
        @chmod($dir, 0777);
        if (!@rmdir($dir)) {
            usleep(50000); 
            @rmdir($dir);
        }
    }
}

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

function slugify($string) {
    $slug = mb_strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/u', '-', $string), '-'));
    return preg_replace('/-+/', '-', $slug);
}

$narrativeDirs = array_filter(glob($sourceBooksDir . '/*'), 'is_dir');
if (empty($narrativeDirs)) {
    die("No narrative folders found in {$sourceBooksDir}\n");
}

$masterCatalog = [];

foreach ($narrativeDirs as $narrativeDir) {
    $narrativeName = basename($narrativeDir);
    $legacyManifest = $narrativeDir . '/katie.json';
    $metaManifest = $narrativeDir . '/ovab.json';
    
    echo "\n========================================================\n";
    echo "Publishing Series: {$narrativeName}\n";
    echo "========================================================\n";

    $seriesMeta = [];
    if (file_exists($legacyManifest)) {
        $seriesMeta = json_decode(file_get_contents($legacyManifest), true) ?: [];
    } elseif (file_exists($metaManifest)) {
        $seriesMeta = json_decode(file_get_contents($metaManifest), true) ?: [];
    }

    $seriesTitle = !empty($seriesMeta['series_title']) ? $seriesMeta['series_title'] : (isset($seriesMeta['title']) ? $seriesMeta['title'] : ucwords(str_replace('-', ' ', $narrativeName)));
    $seriesSlug = !empty($seriesMeta['series_slug']) ? $seriesMeta['series_slug'] : $narrativeName;

    // --- STEP A: DESTRUCTIVE ASSET SYNC ---
    $targetAssetDir = $assetDestDir . '/' . $seriesSlug;
    if (is_dir($targetAssetDir)) {
        echo "  [Assets] Wiping existing CDN directory: {$targetAssetDir}\n";
        rrmdir($targetAssetDir);
    }
    echo "  [Assets] Copying Markdown and assets to CDN...\n";
    rcopy($narrativeDir, $targetAssetDir);
    echo "  [Assets] Sync complete.\n";

    echo "  [Routes] Generating Stardust Engine Route JSON for '{$seriesTitle}'...\n";

    $lastRouteUrl = null;
    $firstRouteUrl = null;
    $routeData = [];
    
    $routeData['common'] = [
        "site" => "raggiesoft-books",
        "theme" => "raggiesoft-books",
        "siteName" => "Ocean View Archives",
        "isSequential" => true,
        "showSidebar" => true,
        "sidebar" => "raggiesoft-books/sidebar-book",
        "headerMenu" => "raggiesoft-books/header-books",
        "footer" => "raggiesoft-books/footer-books",
        "navbarBrandLogo" => "/raggiesoft-books/images/logos/oceanview-archives.svg",
        "navbarBrandText" => '<span class="ova-serif fw-bold" style="color: #E3B27C;">Ocean View Archives</span>',
        "navbarBrandLink" => "/raggiesoft-books"
    ];

    $overviewUrl = "/raggiesoft-books/books/{$seriesSlug}";
    $overviewRoute = [
        "view" => "pages/raggiesoft-books/books/viewer",
        "title" => "{$seriesTitle}",
        "theme" => "raggiesoft-books"
    ];
    if (file_exists($narrativeDir . '/index.md')) {
        $overviewRoute["filePath"] = "index.md";
    } else {
        $overviewRoute["filePath"] = "__SERIES_LANDING__";
    }
    $routeData[$overviewUrl] = $overviewRoute;

    // Dedicated Table of Contents route
    $tocRouteUrl = "/raggiesoft-books/books/{$seriesSlug}/toc";
    $routeData[$tocRouteUrl] = [
        "view" => "pages/raggiesoft-books/books/viewer",
        "title" => "Table of Contents - {$seriesTitle}",
        "theme" => "raggiesoft-books",
        "filePath" => "__TOC__"
    ];

    $legacyBooks = isset($seriesMeta['books']) ? $seriesMeta['books'] : (isset($seriesMeta[0]) ? $seriesMeta : []);
    $tocBooks = [];
    
    // Auto-discover books
    $bDirs = glob($narrativeDir . '/b*', GLOB_ONLYDIR);
    sort($bDirs);
    
    foreach ($bDirs as $bIndex => $bDir) {
        $bName = basename($bDir);
        $bookNum = intval(str_replace('b', '', $bName));
        
        $bookTitle = "Book {$bookNum}";
        $metaPath = $bDir . '/ovab.json';
        if (file_exists($metaPath)) {
            $meta = json_decode(file_get_contents($metaPath), true);
            if (isset($meta['title'])) $bookTitle = $meta['title'];
        } else {
            // Fallback to katie.json
            if (isset($legacyBooks[$bIndex]['book_title'])) {
                $bookTitle = $legacyBooks[$bIndex]['book_title'];
            }
        }
        $bookSlug = slugify(strip_tags($bookTitle));

        $bookRouteUrl = "/raggiesoft-books/books/{$seriesSlug}/{$bookSlug}";
        $routeData[$bookRouteUrl] = [
            "view" => "pages/raggiesoft-books/books/viewer",
            "title" => $bookTitle,
            "theme" => "raggiesoft-books",
            "filePath" => "__BOOK_TOC__|{$bIndex}"
        ];

        $tocBook = [
            'book_num' => $bookNum,
            'book_title' => $bookTitle,
            'book_url' => $bookRouteUrl,
            'chapters' => []
        ];

        // Auto-discover chapters
        $cDirs = glob($bDir . '/c*', GLOB_ONLYDIR);
        sort($cDirs);
        
        foreach ($cDirs as $cIndex => $cDir) {
            $cName = basename($cDir);
            $chapNum = intval(str_replace('c', '', $cName));
            
            $chapTitle = "Chapter {$chapNum}";
            $metaPath = $cDir . '/ovab.json';
            if (file_exists($metaPath)) {
                $meta = json_decode(file_get_contents($metaPath), true);
                if (isset($meta['title'])) $chapTitle = $meta['title'];
            } else {
                if (isset($legacyBooks[$bIndex]['chapters'][$cIndex]['chap_title'])) {
                    $chapTitle = $legacyBooks[$bIndex]['chapters'][$cIndex]['chap_title'];
                }
            }
            $chapSlug = slugify(strip_tags($chapTitle));

            $chapRouteUrl = "/raggiesoft-books/books/{$seriesSlug}/{$bookSlug}/{$chapSlug}";
            $routeData[$chapRouteUrl] = [
                "view" => "pages/raggiesoft-books/books/viewer",
                "title" => $chapTitle,
                "theme" => "raggiesoft-books",
                "filePath" => "__CHAP_TOC__|{$bIndex}|{$cIndex}"
            ];

            $tocChap = [
                'chap_num' => $chapNum,
                'chap_title' => $chapTitle,
                'chap_url' => $chapRouteUrl,
                'parts' => []
            ];

            // Auto-discover parts
            $pFiles = glob($cDir . '/p*.md');
            sort($pFiles);
            
            foreach ($pFiles as $pIndex => $pFile) {
                $pName = basename($pFile);
                $partNum = intval(str_replace(['p', '.md'], '', $pName));
                
                $yamlTitle = "Part {$partNum}";
                $partContent = file_get_contents($pFile);
                if (preg_match('/^---([\s\S]*?)---/', ltrim($partContent), $matches)) {
                    if (preg_match('/^title:\s*"?([^"\r\n]+)"?/m', $matches[1], $m)) {
                        $yamlTitle = trim($m[1]);
                    }
                }
                
                $partTitle = "Part {$partNum}: {$yamlTitle}";
                $partSlug = slugify(strip_tags($partTitle));
                
                $routeUrl = "/raggiesoft-books/books/{$seriesSlug}/{$bookSlug}/{$chapSlug}/{$partSlug}";
                if ($firstRouteUrl === null) {
                    $firstRouteUrl = $routeUrl;
                }
                
                $relPath = str_replace($narrativeDir . '/', '', $pFile);
                $routeData[$routeUrl] = [
                    "view" => "pages/raggiesoft-books/books/viewer",
                    "title" => $partTitle,
                    "theme" => "raggiesoft-books",
                    "filePath" => $relPath
                ];
                $lastRouteUrl = $routeUrl;
                
                $tocChap['parts'][] = [
                    'part_num' => $partNum,
                    'part_title' => $partTitle,
                    'file_path' => $relPath
                ];
            }
            $tocBook['chapters'][] = $tocChap;
        }
        $tocBooks[] = $tocBook;
    }

    if (!empty($seriesMeta['next_series_url']) && isset($lastRouteUrl)) {
        $routeData[$lastRouteUrl]['nextUrl'] = $seriesMeta['next_series_url'];
        if (!empty($seriesMeta['next_series_text'])) {
            $routeData[$lastRouteUrl]['nextText'] = $seriesMeta['next_series_text'];
        }
    }

    $routeJsonFile = $routesDestDir . '/' . $seriesSlug . '.json';
    file_put_contents(
        $routeJsonFile, 
        json_encode($routeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    echo "  [Routes] Saved legacy hub route: {$seriesSlug}.json\n";

    $newRouteData = [];
    $newRouteData['common'] = $routeData['common'];
    $newRouteData['common']['siteName'] = "Ocean View Archives";
    $newRouteData['common']['theme'] = "oceanview";
    
    $newFirstRouteUrl = null;
    foreach ($routeData as $key => $val) {
        if ($key === 'common') continue;
        $newKey = str_replace('/raggiesoft-books/books', '', $key);
        if (isset($val['nextUrl'])) {
            $val['nextUrl'] = str_replace('/raggiesoft-books/books', '', $val['nextUrl']);
        }
        $newRouteData[$newKey] = $val;
    }

    $newRoutesDestDir = dirname(__DIR__, 2) . '/raggiesoft-book-library/data/routes';
    if (!is_dir($newRoutesDestDir)) mkdir($newRoutesDestDir, 0755, true);
    $newRouteJsonFile = $newRoutesDestDir . '/' . $seriesSlug . '.json';
    file_put_contents(
        $newRouteJsonFile, 
        json_encode($newRouteData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    echo "  [Routes] Saved Ocean View route: {$seriesSlug}.json\n";
    
    $longDesc = '';
    $descFileName = !empty($seriesMeta['series_description_file']) ? $seriesMeta['series_description_file'] : 'landing.md';
    $descFilePath = $narrativeDir . '/' . $descFileName;
    if (file_exists($descFilePath)) {
        $longDesc = file_get_contents($descFilePath);
    }

    // Create the auto-generated TOC for the sidebar (replaces manual katie.json)
    $generatedToc = [
        'series_title' => $seriesTitle,
        'series_slug' => $seriesSlug,
        'series_description' => $seriesMeta['series_description'] ?? '',
        'series_description_long' => $longDesc,
        'series_image' => $seriesMeta['series_image'] ?? '',
        'next_series_url' => $seriesMeta['next_series_url'] ?? '',
        'next_series_text' => $seriesMeta['next_series_text'] ?? '',
        'books' => $tocBooks
    ];
    $tocDestFile = $targetAssetDir . '/toc.json';
    file_put_contents($tocDestFile, json_encode($generatedToc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "  [Assets] Saved auto-discovered TOC to toc.json\n";

    $masterCatalog[] = [
        'slug' => $seriesSlug,
        'title' => $seriesTitle,
        'description' => $seriesMeta['series_description'] ?? '',
        'image' => $seriesMeta['series_image'] ?? '',
        'first_route' => $firstRouteUrl,
        'folder' => $narrativeName,
        'hide' => isset($seriesMeta['hide']) ? $seriesMeta['hide'] : false
    ];
}

$catalogFile = $assetDestDir . '/catalog.json';
file_put_contents($catalogFile, json_encode($masterCatalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Build the search index
include __DIR__ . '/build_search_index.php';

echo "========================================================\n";
echo "Publishing Complete! CDN updated and Stardust Routes mapped.\n";
echo "Master Catalog saved to: catalog.json\n";
echo "========================================================\n";
?>
