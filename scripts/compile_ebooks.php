<?php
/**
 * ============================================================================
 * READ-ALONG TEACHING OVERVIEW: compile_ebooks.php
 * ============================================================================
 * WHAT THIS FILE IS:
 *   The master eBook publishing compiler for Ocean View Archives and RaggieSoft
 *   Story Crossroads. It takes each novel's single consolidated Markdown manuscript
 *   (generated beforehand by `lump_narratives.php`), translates RaggieSoft's
 *   custom semantic tags (`<aac>`, `<asl>`, `<led>`, `<lang>`, `{{CDN}}`) into
 *   print-ready Markdown, runs the `pandoc` CLI tool to build 4 downloadable
 *   eBook editions per series, and stitches the resulting file sizes into all
 *   book catalog JSON manifests.
 *
 * HOW IT IS RUN:
 *   Executed from the terminal (or automatically inside `paige.php` / `jenna-sync.sh`):
 *     `php compile_ebooks.php`          (Incremental: skips unchanged novels via SHA-1 hash)
 *     `php compile_ebooks.php --force`  (Full rebuild: regenerates every EPUB & PDF)
 *   (Strictly keeps `<?php` on Line 1 for PHP CLI compatibility.)
 *
 * WHAT INPUTS IT CONSUMES:
 *   1. `raggiesoft-narratives/books-ai-lump/<slug>/*-complete.md` (from `lump_narratives.php`).
 *   2. Local scene illustrations inside `raggiesoft-assets/` (mapped from `{{CDN}}`).
 *   3. Existing `toc.json`, `catalog.json`, and `books.raggiesoft.com/data/routes/<slug>.json`.
 *
 * WHAT COMPILED OUTPUT IT PRODUCES:
 *   For every novel `<slug>` inside `raggiesoft-assets/raggiesoft-books/books/<slug>/`:
 *     1. `<slug>.epub`      — Illustrated EPUB eBook (with embedded high-res artwork & TOC)
 *     2. `<slug>.pdf`       — Illustrated PDF edition (via WeasyPrint or Pandoc fallback)
 *     3. `<slug>-text.epub` — Lightweight Text-Only EPUB (images stripped for fast downloads)
 *     4. `<slug>-text.pdf`  — Lightweight Text-Only PDF
 *     5. `.ebook_build_hash` — SHA-1 fingerprint of the input Markdown for instant incremental skips
 *   Plus human-readable `"ebook_sizes"` (`"36.8 MB"`, `"690 KB"`, etc.) written into
 *   `toc.json`, `catalog.json`, `data/routes/<slug>.json`, and `data/ebook-sizes.json`!
 *
 * 100-LEVEL PHP CLI CONCEPTS TAUGHT IN THIS FILE:
 *   - `dirname(__DIR__, 2)`: Climbing up directory levels cleanly without `../../` strings.
 *   - Content-Addressable Caching (`sha1_file()`): Why hashing file contents is
 *     even more reliable than checking timestamps (`filemtime()`).
 *   - `preg_replace_callback()`: Running custom PHP parsing functions on every
 *     regex match (used here for `<led>` bus matrix signs and `<lang>` translations).
 *   - Safe Subprocess Execution (`escapeshellarg()` + `exec()`): Invoking external
 *     CLI binaries (`pandoc`) without shell-injection or spaces-in-filenames bugs.
 *   - Reference Loop Cleanup (`unset($entry)` after `foreach ($arr as &$entry)`).
 *
 * @license MIT
 * ============================================================================
 */

/* ============================================================================
 * STEP 1: RESOLVE WORKSPACE DIRECTORY COORDINATES
 * ============================================================================
 * `__DIR__` is `.../raggiesoft-servers/raggiesoft-narratives/scripts`.
 * `dirname(__DIR__, 2)` climbs 2 parent folders up to `.../raggiesoft-servers`! */
$workspaceRoot = dirname(__DIR__, 2);
$lumpDir       = $workspaceRoot . '/raggiesoft-narratives/books-ai-lump';
$outBaseDir    = $workspaceRoot . '/raggiesoft-assets/raggiesoft-books/books';
/* Locate the RaggieSoft Book Reader web app directory (`subdomains/books` in the
 * current monorepo, with a fallback to legacy `raggiesoft-book-library`). */
$booksAppDir   = is_dir($workspaceRoot . '/raggiesoft.com/subdomains/books')
    ? ($workspaceRoot . '/raggiesoft.com/subdomains/books')
    : ($workspaceRoot . '/raggiesoft-book-library');

/**
 * Helper: Converts a raw integer byte count (from `filesize()`) into a clean,
 * consumer-friendly label like `"36.8 MB"`, `"2.8 MB"`, `"690 KB"`, or `"5.3 KB"`.
 *
 * Binary unit math (`1024` bytes per KiB):
 *   - `< 1,024`           -> Bytes (`B`)
 *   - `< 1,048,576`       -> Kilobytes (`KB` = `bytes / 1024`; 1 decimal under 10 KB, rounded integer >= 10 KB)
 *   - `< 1,073,741,824`   -> Megabytes (`MB` = `bytes / 1048576`; up to 1 decimal, trailing `.0` trimmed)
 *   - `>= 1,073,741,824`  -> Gigabytes (`GB` = `bytes / 1073741824`; up to 2 decimals, trailing zeros trimmed)
 *
 * @param int $bytes Raw file size in bytes.
 * @return string Formatted size string, or `""` when `$bytes <= 0`.
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
            /* `number_format($kb, 1, '.', '')` produces e.g. `"5.0"` or `"5.3"`.
             * Nested `rtrim(..., '0')` then `rtrim(..., '.')` turns `"5.0"` into `"5"`! */
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
 * Helper: Measures all compiled eBook deliverables (plus optional `-package.7z`)
 * inside a book's asset directory and returns both formatted display strings
 * (`'epub' => '14.2 MB'`) and exact integer byte counts (`'epub_bytes' => 14889779`).
 *
 * @param string $bookAssetDir Path to `raggiesoft-assets/raggiesoft-books/books/<slug>`.
 * @param string $seriesSlug   Canonical series folder slug (e.g., `'katie'`, `'ad-astra'`).
 * @return array Associative map of human-readable labels and raw byte sizes.
 */
function getSeriesEbookSizes(string $bookAssetDir, string $seriesSlug): array {
    /* PHP caches `filesize()` and `file_exists()` metadata in memory for speed!
     * Calling `clearstatcache()` forces PHP to read the freshly generated files'
     * brand-new sizes from disk instead of stale pre-build values. */
    clearstatcache();
    $files = [
        'epub'       => $bookAssetDir . '/' . $seriesSlug . '.epub',
        'pdf'        => $bookAssetDir . '/' . $seriesSlug . '.pdf',
        'text_epub'  => $bookAssetDir . '/' . $seriesSlug . '-text.epub',
        'text_pdf'   => $bookAssetDir . '/' . $seriesSlug . '-text.pdf',
        'package_7z' => $bookAssetDir . '/' . $seriesSlug . '-package.7z',
    ];
    $sizes = [
        'epub'             => '',
        'pdf'              => '',
        'text_epub'        => '',
        'text_pdf'         => '',
        'package_7z'       => '',
        'epub_bytes'       => 0,
        'pdf_bytes'        => 0,
        'text_epub_bytes'  => 0,
        'text_pdf_bytes'   => 0,
        'package_7z_bytes' => 0,
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

/* ============================================================================
 * STEP 2: PARSE CLI FLAGS & DISCOVER NOVEL LUMP DIRECTORIES
 * ============================================================================
 * In PHP CLI scripts, `$argv` is a built-in array of command-line words:
 *   `$argv[0]` = `'compile_ebooks.php'`, `$argv[1]` = `'--force'` (if passed).
 * Passing `true` as the 3rd argument to `in_array()` enforces strict `===` comparison. */
$forceRebuild = in_array('--force', $argv ?? [], true);

if (!is_dir($lumpDir)) {
    /* Write errors to `STDERR` (Standard Error stream) and exit with non-zero code `1`
     * so calling shell scripts (`paige.php` / `jenna-sync.sh`) know a failure occurred. */
    fwrite(STDERR, "Error: AI lump directory not found at: {$lumpDir}\n");
    exit(1);
}

/* `glob($lumpDir . '/*')` lists everything in `books-ai-lump/`, and
 * `array_filter(..., 'is_dir')` keeps only directories (ignoring stray files like `.DS_Store`). */
$dirs = array_filter(glob($lumpDir . '/*'), 'is_dir');

/* ============================================================================
 * STEP 3: COMPILE EACH SERIES INTO ILLUSTRATED + TEXT-ONLY EPUB & PDF EDITIONS
 * ============================================================================ */
foreach ($dirs as $novelDir) {
    $slug = basename($novelDir);

    /* Find `<novel-title>-complete.md` inside `books-ai-lump/<slug>/`. */
    $completeFiles = glob($novelDir . '/*-complete.md');
    if (empty($completeFiles)) {
        continue;
    }
    $mdFile = $completeFiles[0];

    /* Define all 4 output file paths plus `.ebook_build_hash`. */
    $outDir       = $outBaseDir . '/' . $slug;
    $epubFile     = $outDir . '/' . $slug . '.epub';
    $pdfFile      = $outDir . '/' . $slug . '.pdf';
    $textEpubFile = $outDir . '/' . $slug . '-text.epub';
    $textPdfFile  = $outDir . '/' . $slug . '-text.pdf';
    $hashFile     = $outDir . '/.ebook_build_hash';

    /* Compute a 40-character SHA-1 cryptographic fingerprint of the entire
     * source Markdown file. Even a 1-character comma edit changes `$currentHash`! */
    $currentHash = sha1_file($mdFile);

    /* INCREMENTAL BUILD CHECK:
     * Pandoc PDF typesetting across a dozen full-length novels can take minutes.
     * If `--force` was NOT passed AND all 4 eBook files already exist on disk,
     * check whether `.ebook_build_hash` matches `$currentHash` (or fall back to
     * `filemtime` modification timestamps if `.ebook_build_hash` wasn't created yet). */
    if (!$forceRebuild && file_exists($epubFile) && file_exists($pdfFile) && file_exists($textEpubFile) && file_exists($textPdfFile)) {
        if (file_exists($hashFile)) {
            $savedHash = trim((string)@file_get_contents($hashFile));
            if ($savedHash === $currentHash) {
                echo "Skipping $slug (up to date - hash verified). Use --force to rebuild.\n";
                continue;
            }
        } else {
            /* Legacy timestamp check: if all 4 outputs are newer than `$mdFile`,
             * backfill `.ebook_build_hash` now so future builds verify by hash. */
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

    /* ------------------------------------------------------------------------
     * SUBSTEP 3A: RESOLVE IMAGE PLACEHOLDERS FOR LOCAL PANDOC EMBEDDING
     * In Markdown manuscripts, scene illustrations use `{{CDN}}/raggiesoft-books/...`
     * so web apps can point to `https://assets.raggiesoft.com`. For offline EPUB/PDF
     * compilation, we swap `{{CDN}}` and `{{cms}}` with the local disk path to
     * `raggiesoft-assets` so Pandoc reads the PNG/JPG/WebP bytes straight off NVMe!
     * ------------------------------------------------------------------------ */
    $localCdnPath = $workspaceRoot . '/raggiesoft-assets';
    $content = str_replace(['{{CDN}}', '{{cms}}'], $localCdnPath, $content);

    /* Strip web-layout URL fragments like `image.jpg#fullwidth` or `image.png#center`
     * because Pandoc treats `#fullwidth` as part of the local filename on disk and fails to find it. */
    $content = preg_replace('/(\.(?:jpg|jpeg|png|gif|webp))#[^)]+/', '$1', $content);

    /* ------------------------------------------------------------------------
     * SUBSTEP 3B: TRANSLATE CUSTOM NARRATIVE SEMANTIC TAGS INTO EBOOK MARKDOWN
     * Web readers style custom tags (`<aac>`, `<asl>`, `<led>`, `<lang>`) with CSS,
     * whereas EPUB/PDF e-readers expect standard Markdown typography.
     * ------------------------------------------------------------------------ */
    /* `<aac>...</aac>`: Augmentative & Alternative Communication tablet speech -> `*italics*`. */
    $content = preg_replace('/<aac>(.*?)<\/aac>/is', '*$1*', $content);

    /* `<asl>`, `<sgn>`, `<sms>`, `<pa>`: Sign language, text messages, & PA announcements -> `*italics*`.
     * Notice `\1` in `</\1>`: that "backreference" ensures `<asl>` only closes on `</asl>`, etc. */
    $content = preg_replace('/<(asl|sgn|sms|pa)>(.*?)<\/\1>/is', '*$2*', $content);

    /* `<led>...</led>`: Transit bus amber LED destination matrix signs -> `` `[913 | VA BEACH / SHORE & PLEAS HSE]` ``.
     * `preg_replace_callback()` invokes this anonymous function for every `<led>` tag,
     * supporting both HTML attributes (`<led route="913" line1="VA BEACH" line2="..."/>`)
     * and pipe-delimited inner text (`<led>913 | VA BEACH | SHORE & PLEAS HSE</led>`). */
    $content = preg_replace_callback('/<led\b([^>]*?)(?:\/>|>(.*?)<\/led>)/isu', function($matches) {
        $attrStr = $matches[1] ?? '';
        $rawInner = $matches[2] ?? '';
        $attrs = [];
        /* Parse optional `key="value"` or `key='value'` attributes on `<led ...>`. */
        if ($attrStr !== '' && preg_match_all('/([a-zA-Z0-9_-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/u', $attrStr, $am, PREG_SET_ORDER)) {
            foreach ($am as $m) {
                $attrs[strtolower($m[1])] = trim($m[2] !== '' ? $m[2] : ($m[3] ?? ''));
            }
        }
        $route = $attrs['route'] ?? '';
        $lines = [];
        if (isset($attrs['line1']) || isset($attrs['line2'])) {
            if (($attrs['line1'] ?? '') !== '') $lines[] = $attrs['line1'];
            if (($attrs['line2'] ?? '') !== '') $lines[] = $attrs['line2'];
        } else {
            /* Normalize `<br>` tags and newlines into `|` pipes, then split into trimmed segments. */
            $norm = preg_replace('/<br\s*\/?>|\R/iu', '|', $rawInner);
            $parts = array_values(array_filter(array_map('trim', explode('|', (string)$norm)), fn($s) => $s !== ''));
            if ($route === '' && count($parts) >= 3) {
                /* 3+ segments: 1st segment is the route number; remaining segments form top & bottom lines. */
                $route = array_shift($parts);
                $lines = [$parts[0], implode(' ', array_slice($parts, 1))];
            } elseif (count($parts) >= 2) {
                $lines = [$parts[0], implode(' ', array_slice($parts, 1))];
            } elseif (count($parts) === 1) {
                $lines = [$parts[0]];
            }
        }
        $joinedLines = implode(' / ', $lines);
        $label = ($route !== '' && $joinedLines !== '')
            ? ($route . ' | ' . $joinedLines)
            : ($route !== '' ? $route : $joinedLines);
        /* Sanitize any stray backtick inside `$label` so our inline code `` `[...]` `` never breaks. */
        $label = str_replace('`', "'", trim($label));
        return $label !== '' ? "`[{$label}]`" : '';
    }, $content);

    /* `<lang trans="English translation">Foreign phrase</lang>` -> `*Foreign phrase* (English translation)`.
     * In print/EPUB there is no mouse click to toggle translations like `reader.js` does on the web,
     * so printing both side-by-side preserves 100% comprehension offline! */
    $content = preg_replace_callback('/<lang(?:[^>]*?)trans="([^"]+)"(?:[^>]*?)>(.*?)<\/lang>/is', function($matches) {
        $translation = $matches[1];
        $original    = $matches[2];
        return "*{$original}* ({$translation})";
    }, $content);
    /* Fallback for `<lang>` tags without a `trans="..."` attribute -> italicize the phrase. */
    $content = preg_replace('/<lang[^>]*>(.*?)<\/lang>/is', '*$1*', $content);

    /* ------------------------------------------------------------------------
     * SUBSTEP 3C: WRITE TEMPORARY PANDOC STAGING FILES (ILLUSTRATED + TEXT-ONLY)
     * ------------------------------------------------------------------------ */
    $tmpMd = $novelDir . '/' . $slug . '-pandoc.md';
    file_put_contents($tmpMd, $content);

    /* Build the Text-Only Edition source by stripping all `![alt](path)` Markdown image tags
     * and collapsing triple blank lines (`\n\n\n+`) left behind into clean paragraph breaks (`\n\n`). */
    $textContent = preg_replace('/!\[[^\]]*\]\([^)]+\)\s*/', '', $content);
    $textContent = preg_replace("/\n{3,}/", "\n\n", $textContent);
    $tmpTextMd   = $novelDir . '/' . $slug . '-pandoc-text.md';
    file_put_contents($tmpTextMd, $textContent);

    /* Ensure `raggiesoft-assets/raggiesoft-books/books/<slug>/` exists. */
    if (!is_dir($outDir)) {
        mkdir($outDir, 0755, true);
    }

    /* ------------------------------------------------------------------------
     * SUBSTEP 3D: RUN PANDOC CLI FOR ALL 4 DELIVERABLES
     * Security & Reliability Note:
     * Always wrap dynamic file paths in `escapeshellarg()` before passing them
     * to `exec()`. It wraps the path in single quotes and escapes special shell
     * characters so spaces or apostrophes in folder names never break the command!
     * `--toc --toc-depth=3` generates an interactive 3-level Table of Contents
     * (`# Era` -> `## Chapter` -> `### Part`).
     * ------------------------------------------------------------------------ */

    /* Deliverable 1: Illustrated EPUB */
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

    /* Deliverable 2: Illustrated PDF (tries modern CSS-paged-media `weasyprint` first;
     * if `weasyprint` isn't installed, shell `||` falls back to Pandoc's default PDF engine). */
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

    /* Deliverable 3: Text-Only EPUB (No Images) */
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

    /* Deliverable 4: Text-Only PDF (No Images) */
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

    /* Delete (`unlink`) temporary `-pandoc.md` staging files so `books-ai-lump/` stays clean. */
    if (file_exists($tmpMd)) {
        unlink($tmpMd);
    }
    if (file_exists($tmpTextMd)) {
        unlink($tmpTextMd);
    }

    /* Save `.ebook_build_hash` ONLY when all 4 deliverables succeeded on disk. */
    if (file_exists($epubFile) && file_exists($pdfFile) && file_exists($textEpubFile) && file_exists($textPdfFile)) {
        file_put_contents($hashFile, $currentHash);
    }
    echo "  Done.\n";
}

/* ============================================================================
 * STEP 4: STITCH HUMAN-READABLE EBOOK SIZES INTO ALL CATALOG & ROUTE MANIFESTS
 * ============================================================================
 * Even for skipped (already up-to-date) books, we scan every compiled folder in
 * `raggiesoft-assets/raggiesoft-books/books/<slug>` and sync `"ebook_sizes"` into:
 *   1. `<bookOutDir>/toc.json`
 *   2. `books.raggiesoft.com/data/routes/<slug>.json`
 *   3. `raggiesoft-assets/raggiesoft-books/books/catalog.json`
 *   4. `books.raggiesoft.com/data/ebook-sizes.json`
 * so download buttons across web and desktop apps always show accurate MB/KB badges!
 */
$allEbookSizes = [];
$bookOutDirs = array_filter(glob($outBaseDir . '/*'), 'is_dir');
foreach ($bookOutDirs as $bookOutDir) {
    $slug = basename($bookOutDir);
    $sizes = getSeriesEbookSizes($bookOutDir, $slug);
    $allEbookSizes[$slug] = $sizes;

    /* 4A. Inject `ebook_sizes` into per-series `toc.json`. */
    $tocJsonPath = $bookOutDir . '/toc.json';
    if (is_file($tocJsonPath)) {
        $tocData = json_decode((string)@file_get_contents($tocJsonPath), true);
        if (is_array($tocData)) {
            $tocData['ebook_sizes'] = $sizes;
            file_put_contents($tocJsonPath, json_encode($tocData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    /* 4B. Inject `common.ebook_sizes` into `books.raggiesoft.com/data/routes/<slug>.json`. */
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

/* 4C. Inject `ebook_sizes` into each matching series card in master `catalog.json`.
 * Classic PHP Gotcha: After `foreach ($catalogData as &$entry)`, `$entry` STILL
 * points by reference to the last element of `$catalogData`! Calling `unset($entry)`
 * immediately after the loop breaks that reference safely so later code can never
 * overwrite the last catalog entry by accident. */
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

/* 4D. Write standalone lookup manifest `books.raggiesoft.com/data/ebook-sizes.json`. */
if (is_dir($booksAppDir . '/data')) {
    file_put_contents(
        $booksAppDir . '/data/ebook-sizes.json',
        json_encode($allEbookSizes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

echo "All E-Books compiled, sizes stitched, and verified!\n";
