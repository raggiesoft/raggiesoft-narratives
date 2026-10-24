<?php
/**
 * ============================================================================
 * compile_ebooks.php
 * ============================================================================
 * Converts aggregated narrative Markdown lump files into standalone EPUB and
 * PDF e-books using Pandoc.
 *
 * Architecture & Flow:
 * 1. Discovers all story/series directories in `raggiesoft-narratives/books-ai-lump`.
 * 2. Dynamically locates the primary completed narrative file (`*-complete.md`).
 * 3. Preprocesses the narrative Markdown for publication:
 *    - Maps CDN asset placeholders (`{{CDN}}`) to absolute local filesystem paths.
 *    - Strips CSS/URL fragment hashes (e.g., `#fullwidth`) that disrupt Pandoc's
 *      local image resolution.
 *    - Translates semantic narrative markup tags (`<aac>`, `<asl>`, `<lang>`, etc.)
 *      into standard Markdown styling (italics, inline translations).
 * 4. Checks modification timestamps (`mtime`) against existing outputs in
 *    `raggiesoft-assets/raggiesoft-books/books/{slug}/`. If outputs are newer than
 *    the source Markdown and `--force` is not specified, skipping avoids redundant
 *    heavy Pandoc conversions.
 * 5. Executes Pandoc CLI to generate both EPUB and PDF deliverables with structured
 *    tables of contents.
 *
 * Usage:
 *   php compile_ebooks.php          # Incremental build (skips up-to-date files)
 *   php compile_ebooks.php --force  # Rebuilds all EPUB and PDF deliverables
 *
 * @license MIT
 * ============================================================================
 */

// Establish filesystem root coordinates
$workspaceRoot = dirname(__DIR__, 2);
$lumpDir       = $workspaceRoot . '/raggiesoft-narratives/books-ai-lump';
$outBaseDir    = $workspaceRoot . '/raggiesoft-assets/raggiesoft-books/books';
$booksAppDir   = is_dir($workspaceRoot . '/raggiesoft.com/subdomains/books')
    ? ($workspaceRoot . '/raggiesoft.com/subdomains/books')
    : ($workspaceRoot . '/raggiesoft-book-library');

/**
 * Formats a raw byte count into a pleasant, human-readable file size string
 * (e.g., "36.8 MB", "2.8 MB", "690 KB", "5.3 KB") for eBook download links.
 *
 * @param int $bytes Raw file size in bytes.
 * @return string Pleasant human-readable size string, or empty string if invalid.
 */
function formatEbookFileSize(int $bytes): string {
    if ($bytes <= 0) {
        return '';
    }
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $kb = $bytes / 1024;
    if ($kb < 1024) {
        if ($kb < 10) {
            $formatted = rtrim(rtrim(number_format($kb, 1, '.', ''), '0'), '.');
            return $formatted . ' KB';
        }
        return (string)round($kb) . ' KB';
    }
    $mb = $bytes / 1048576;
    if ($mb < 1024) {
        $formatted = rtrim(rtrim(number_format($mb, 1, '.', ''), '0'), '.');
        return $formatted . ' MB';
    }
    $gb = $bytes / 1073741824;
    $formatted = rtrim(rtrim(number_format($gb, 2, '.', ''), '0'), '.');
    return $formatted . ' GB';
}

/**
 * Inspects a compiled book asset directory and returns human-readable and raw byte
 * file sizes for all 4 eBook deliverables (Illustrated EPUB/PDF + Text-Only EPUB/PDF).
 *
 * @param string $bookAssetDir Path to `raggiesoft-assets/raggiesoft-books/books/<slug>`.
 * @param string $seriesSlug   Canonical series slug.
 * @return array Associative array of formatted strings and byte counts.
 */
function getSeriesEbookSizes(string $bookAssetDir, string $seriesSlug): array {
    clearstatcache();
    $files = [
        'epub'      => $bookAssetDir . '/' . $seriesSlug . '.epub',
        'pdf'       => $bookAssetDir . '/' . $seriesSlug . '.pdf',
        'text_epub' => $bookAssetDir . '/' . $seriesSlug . '-text.epub',
        'text_pdf'  => $bookAssetDir . '/' . $seriesSlug . '-text.pdf',
    ];
    $sizes = [
        'epub'            => '',
        'pdf'             => '',
        'text_epub'       => '',
        'text_pdf'        => '',
        'epub_bytes'      => 0,
        'pdf_bytes'       => 0,
        'text_epub_bytes' => 0,
        'text_pdf_bytes'  => 0,
    ];
    foreach ($files as $key => $filePath) {
        if (is_file($filePath)) {
            $bytes = (int)@filesize($filePath);
            if ($bytes > 0) {
                $sizes[$key] = formatEbookFileSize($bytes);
                $sizes[$key . '_bytes'] = $bytes;
            }
        }
    }
    return $sizes;
}

// Parse command line arguments for optional force flag
$forceRebuild = in_array('--force', $argv ?? [], true);

if (!is_dir($lumpDir)) {
    fwrite(STDERR, "Error: AI lump directory not found at: {$lumpDir}\n");
    exit(1);
}

// Discover all series directories in the lump folder
$dirs = array_filter(glob($lumpDir . '/*'), 'is_dir');

foreach ($dirs as $novelDir) {
    $slug = basename($novelDir);

    // Locate the narrative markdown file (may be named after the slug or the title)
    $completeFiles = glob($novelDir . '/*-complete.md');
    if (empty($completeFiles)) {
        continue;
    }
    $mdFile = $completeFiles[0];

    // Establish output target directory, filenames (Illustrated + Text-Only Editions), and build hash tracking
    $outDir       = $outBaseDir . '/' . $slug;
    $epubFile     = $outDir . '/' . $slug . '.epub';
    $pdfFile      = $outDir . '/' . $slug . '.pdf';
    $textEpubFile = $outDir . '/' . $slug . '-text.epub';
    $textPdfFile  = $outDir . '/' . $slug . '-text.pdf';
    $hashFile     = $outDir . '/.ebook_build_hash';

    $currentHash = sha1_file($mdFile);

    // Incremental build check: skip if all 4 outputs exist and content is verified unchanged
    if (!$forceRebuild && file_exists($epubFile) && file_exists($pdfFile) && file_exists($textEpubFile) && file_exists($textPdfFile)) {
        if (file_exists($hashFile)) {
            $savedHash = trim((string)@file_get_contents($hashFile));
            if ($savedHash === $currentHash) {
                echo "Skipping $slug (up to date - hash verified). Use --force to rebuild.\n";
                continue;
            }
        } else {
            // Backward compatibility for pre-existing builds without hash file:
            // Check mtime; if up-to-date, write hash file now so future runs are instant.
            $srcMtime      = filemtime($mdFile);
            $epubMtime     = filemtime($epubFile);
            $pdfMtime      = filemtime($pdfFile);
            $textEpubMtime = filemtime($textEpubFile);
            $textPdfMtime  = filemtime($textPdfFile);

            if ($epubMtime >= $srcMtime && $pdfMtime >= $srcMtime && $textEpubMtime >= $srcMtime && $textPdfMtime >= $srcMtime) {
                @file_put_contents($hashFile, $currentHash);
                echo "Skipping $slug (up to date - timestamps verified). Use --force to rebuild.\n";
                continue;
            }
        }
    }

    echo "Processing $slug...\n";
    $content = file_get_contents($mdFile);

    // 1. Handle Images:
    // Replace {{CDN}} and {{cms}} placeholders with absolute local filesystem paths so Pandoc embeds them
    $localCdnPath = $workspaceRoot . '/raggiesoft-assets';
    $content = str_replace(['{{CDN}}', '{{cms}}'], $localCdnPath, $content);

    // Strip trailing URL hashes (e.g., #fullwidth, #center) that break Pandoc's file resolver
    $content = preg_replace('/(\.(?:jpg|jpeg|png|gif|webp))#[^)]+/', '$1', $content);

    // 2. Handle Custom Semantic Markup Tags:
    // <aac> tags: Augmentative and Alternative Communication dialogue -> convert to italics
    $content = preg_replace('/<aac>(.*?)<\/aac>/is', '*$1*', $content);

    // <asl>, <sgn>, <sms>: Sign language and text messaging -> convert to italics
    $content = preg_replace('/<(asl|sgn|sms)>(.*?)<\/\1>/is', '*$2*', $content);

    // <lang> tags: Multilingual speech -> convert to italics with inline parenthetical translation
    $content = preg_replace_callback('/<lang(?:[^>]*?)trans="([^"]+)"(?:[^>]*?)>(.*?)<\/lang>/is', function($matches) {
        $translation = $matches[1];
        $original    = $matches[2];
        return "*{$original}* ({$translation})";
    }, $content);
    // Fallback for <lang> tags without translation attribute
    $content = preg_replace('/<lang[^>]*>(.*?)<\/lang>/is', '*$1*', $content);

    // 3. Write preprocessed Markdown (Illustrated Edition) to an intermediate staging file
    $tmpMd = $novelDir . '/' . $slug . '-pandoc.md';
    file_put_contents($tmpMd, $content);

    // Also prepare the Text-Only Edition (No Images) by stripping all Markdown images
    $textContent = preg_replace('/!\[[^\]]*\]\([^)]+\)\s*/', '', $content);
    $textContent = preg_replace("/\n{3,}/", "\n\n", $textContent);
    $tmpTextMd   = $novelDir . '/' . $slug . '-pandoc-text.md';
    file_put_contents($tmpTextMd, $textContent);

    // 4. Ensure the output target directory exists
    if (!is_dir($outDir)) {
        mkdir($outDir, 0755, true);
    }

    // 5. Generate Illustrated EPUB using Pandoc
    echo "  Generating Illustrated EPUB ({$slug}.epub)...\n";
    $epubCmd = sprintf(
        'pandoc %s -o %s --toc --toc-depth=3',
        escapeshellarg($tmpMd),
        escapeshellarg($epubFile)
    );
    exec($epubCmd, $output, $returnVar);
    if ($returnVar !== 0) {
        fwrite(STDERR, "  [Error] Illustrated EPUB generation failed for {$slug}\n");
    }

    // 6. Generate Illustrated PDF using Pandoc (with weasyprint engine fallback)
    echo "  Generating Illustrated PDF ({$slug}.pdf)...\n";
    $pdfCmd = sprintf(
        'pandoc %s -o %s --toc --toc-depth=3 --pdf-engine=weasyprint 2>/dev/null || pandoc %s -o %s --toc --toc-depth=3',
        escapeshellarg($tmpMd),
        escapeshellarg($pdfFile),
        escapeshellarg($tmpMd),
        escapeshellarg($pdfFile)
    );
    exec($pdfCmd, $output, $returnVar);
    if ($returnVar !== 0) {
        fwrite(STDERR, "  [Warning] Illustrated PDF generation failed for {$slug}. Ensure wkhtmltopdf or pdflatex is installed.\n");
    }

    // 7. Generate Text-Only EPUB (No Images)
    echo "  Generating Text-Only EPUB ({$slug}-text.epub)...\n";
    $textEpubCmd = sprintf(
        'pandoc %s -o %s --toc --toc-depth=3',
        escapeshellarg($tmpTextMd),
        escapeshellarg($textEpubFile)
    );
    exec($textEpubCmd, $output, $returnVar);
    if ($returnVar !== 0) {
        fwrite(STDERR, "  [Error] Text-Only EPUB generation failed for {$slug}\n");
    }

    // 8. Generate Text-Only PDF (No Images)
    echo "  Generating Text-Only PDF ({$slug}-text.pdf)...\n";
    $textPdfCmd = sprintf(
        'pandoc %s -o %s --toc --toc-depth=3 --pdf-engine=weasyprint 2>/dev/null || pandoc %s -o %s --toc --toc-depth=3',
        escapeshellarg($tmpTextMd),
        escapeshellarg($textPdfFile),
        escapeshellarg($tmpTextMd),
        escapeshellarg($textPdfFile)
    );
    exec($textPdfCmd, $output, $returnVar);
    if ($returnVar !== 0) {
        fwrite(STDERR, "  [Warning] Text-Only PDF generation failed for {$slug}.\n");
    }

    // 9. Cleanup intermediate staging files
    if (file_exists($tmpMd)) {
        unlink($tmpMd);
    }
    if (file_exists($tmpTextMd)) {
        unlink($tmpTextMd);
    }

    // 10. Record the build hash upon successful completion of all 4 deliverables
    if (file_exists($epubFile) && file_exists($pdfFile) && file_exists($textEpubFile) && file_exists($textPdfFile)) {
        file_put_contents($hashFile, $currentHash);
    }
    echo "  Done.\n";
}

// 11. Stitch human-readable file sizes for all books into toc.json, catalog.json, route maps, and ebook-sizes.json
$allEbookSizes = [];
$bookOutDirs = array_filter(glob($outBaseDir . '/*'), 'is_dir');
foreach ($bookOutDirs as $bookOutDir) {
    $slug = basename($bookOutDir);
    $sizes = getSeriesEbookSizes($bookOutDir, $slug);
    $allEbookSizes[$slug] = $sizes;

    // Update series toc.json if present
    $tocJsonPath = $bookOutDir . '/toc.json';
    if (is_file($tocJsonPath)) {
        $tocData = json_decode((string)@file_get_contents($tocJsonPath), true);
        if (is_array($tocData)) {
            $tocData['ebook_sizes'] = $sizes;
            file_put_contents($tocJsonPath, json_encode($tocData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    // Update series route map in books.raggiesoft.com/data/routes/<slug>.json if present
    $routeJsonPath = $booksAppDir . '/data/routes/' . $slug . '.json';
    if (is_file($routeJsonPath)) {
        $routeData = json_decode((string)@file_get_contents($routeJsonPath), true);
        if (is_array($routeData)) {
            if (!isset($routeData['common']) || !is_array($routeData['common'])) {
                $routeData['common'] = [];
            }
            $routeData['common']['ebook_sizes'] = $sizes;
            file_put_contents($routeJsonPath, json_encode($routeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }
}

// Update master catalog.json if present
$catalogJsonPath = $outBaseDir . '/catalog.json';
if (is_file($catalogJsonPath)) {
    $catalogData = json_decode((string)@file_get_contents($catalogJsonPath), true);
    if (is_array($catalogData)) {
        foreach ($catalogData as &$entry) {
            $entrySlug = $entry['slug'] ?? '';
            if ($entrySlug !== '' && isset($allEbookSizes[$entrySlug])) {
                $entry['ebook_sizes'] = $allEbookSizes[$entrySlug];
            }
        }
        unset($entry);
        file_put_contents($catalogJsonPath, json_encode($catalogData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

// Write standalone ebook-sizes.json manifest into books.raggiesoft.com/data/
if (is_dir($booksAppDir . '/data')) {
    file_put_contents(
        $booksAppDir . '/data/ebook-sizes.json',
        json_encode($allEbookSizes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

echo "All E-Books compiled, sizes stitched, and verified!\n";
