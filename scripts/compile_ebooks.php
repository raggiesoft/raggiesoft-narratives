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

    // Establish output target directory, filenames, and build hash tracking
    $outDir   = $outBaseDir . '/' . $slug;
    $epubFile = $outDir . '/' . $slug . '.epub';
    $pdfFile  = $outDir . '/' . $slug . '.pdf';
    $hashFile = $outDir . '/.ebook_build_hash';

    $currentHash = sha1_file($mdFile);

    // Incremental build check: skip if both outputs exist and content is verified unchanged
    if (!$forceRebuild && file_exists($epubFile) && file_exists($pdfFile)) {
        if (file_exists($hashFile)) {
            $savedHash = trim((string)@file_get_contents($hashFile));
            if ($savedHash === $currentHash) {
                echo "Skipping $slug (up to date - hash verified). Use --force to rebuild.\n";
                continue;
            }
        } else {
            // Backward compatibility for pre-existing builds without hash file:
            // Check mtime; if up-to-date, write hash file now so future runs are instant.
            $srcMtime  = filemtime($mdFile);
            $epubMtime = filemtime($epubFile);
            $pdfMtime  = filemtime($pdfFile);

            if ($epubMtime >= $srcMtime && $pdfMtime >= $srcMtime) {
                @file_put_contents($hashFile, $currentHash);
                echo "Skipping $slug (up to date - timestamps verified). Use --force to rebuild.\n";
                continue;
            }
        }
    }

    echo "Processing $slug...\n";
    $content = file_get_contents($mdFile);

    // 1. Handle Images:
    // Replace {{CDN}} placeholders with absolute local filesystem paths so Pandoc embeds them
    $localCdnPath = $workspaceRoot . '/raggiesoft-assets';
    $content = str_replace('{{CDN}}', $localCdnPath, $content);

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

    // 3. Write preprocessed Markdown to an intermediate staging file
    $tmpMd = $novelDir . '/' . $slug . '-pandoc.md';
    file_put_contents($tmpMd, $content);

    // 4. Ensure the output target directory exists
    if (!is_dir($outDir)) {
        mkdir($outDir, 0755, true);
    }

    // 5. Generate EPUB using Pandoc
    echo "  Generating EPUB...\n";
    $epubCmd = sprintf(
        'pandoc %s -o %s --toc --toc-depth=3',
        escapeshellarg($tmpMd),
        escapeshellarg($epubFile)
    );
    exec($epubCmd, $output, $returnVar);
    if ($returnVar !== 0) {
        fwrite(STDERR, "  [Error] EPUB generation failed for {$slug}\n");
    }

    // 6. Generate PDF using Pandoc (with weasyprint engine fallback)
    echo "  Generating PDF...\n";
    $pdfCmd = sprintf(
        'pandoc %s -o %s --toc --toc-depth=3 --pdf-engine=weasyprint 2>/dev/null || pandoc %s -o %s --toc --toc-depth=3',
        escapeshellarg($tmpMd),
        escapeshellarg($pdfFile),
        escapeshellarg($tmpMd),
        escapeshellarg($pdfFile)
    );
    exec($pdfCmd, $output, $returnVar);
    if ($returnVar !== 0) {
        fwrite(STDERR, "  [Warning] PDF generation failed for {$slug}. Ensure wkhtmltopdf or pdflatex is installed.\n");
    }

    // 7. Cleanup intermediate staging file
    if (file_exists($tmpMd)) {
        unlink($tmpMd);
    }

    // 8. Record the build hash upon successful completion
    if (file_exists($epubFile) && file_exists($pdfFile)) {
        file_put_contents($hashFile, $currentHash);
    }
    echo "  Done.\n";
}

echo "All E-Books compiled and verified!\n";
