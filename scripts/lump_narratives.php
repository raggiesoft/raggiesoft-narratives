<?php
/**
 * ============================================================================
 * ARCHITECTURE & MODULE OVERVIEW: lump_narratives.php
 * ============================================================================
 * Purpose:
 * This script aggregates fragmented Markdown narrative files (scenes/parts) into
 * consolidated novel-level Markdown files (`books-ai-lump/<slug>/<novel>-complete.md`).
 * It reads `ovab.json` and optional `crossroads.json` manifests to determine the
 * structural hierarchy and coequal Story Crossroads branches.
 *
 * Architectural Role:
 * Functions as a build/compile step for the narrative eBook pipeline (`compile_ebooks.php`).
 * When a novel defines coequal Story Crossroads (`crossroads.json`), this script:
 *   1. Emits all shared pre-Crossroads Eras, Chapters, and Parts up through `trigger_part`.
 *   2. Injects an accessible, clickable internal Story Crossroads menu (`{#crossroads-<id>}`)
 *      at the end of `trigger_part` so EPUB & PDF readers can jump straight to their chosen path.
 *   3. Emits each coequal Story Path under its own anchored `# Story Path: <Label>` section
 *      (`{#crossroads-path-<option-id>}`) with bidirectional return links back to the Crossroads.
 * ============================================================================
 */

echo "Starting AI Context Lumper...\n";
echo "Validating directories...\n";

// Define working directories relative to `/raggiesoft-narratives/scripts`
$baseDir = dirname(__DIR__);
$booksBaseDir = $baseDir . '/books';
$lumpBaseDir = $baseDir . '/books-ai-lump';

if (!is_dir($booksBaseDir)) {
    die("Error: Base books directory not found at {$booksBaseDir}\n");
}

if (!is_dir($lumpBaseDir)) {
    echo "Creating master output directory: /books-ai-lump/\n";
    mkdir($lumpBaseDir, 0755, true);
}

function slugify($string) {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string), '-'));
    return preg_replace('/-+/', '-', $slug);
}

/**
 * Formats a single `pXXX.md` file into consolidated Markdown for Pandoc EPUB/PDF compilation.
 */
function renderLumpedPartMarkdown(string $partFile, string $baseDir, int $chapPartCount): string {
    $partName = basename($partFile);
    $partNum = intval(str_replace(['p', '.md'], '', $partName));

    $relPath = str_replace($baseDir . '/', '', $partFile);
    echo "      -> [Part] Appending: {$relPath}\n";

    $partContent = (string)file_get_contents($partFile);

    $title = '';
    $date = '';
    $stardate = '';
    $realmTime = '';
    $time = '';
    $timezone = '';
    $sceneImages = [];
    $sceneImageAlts = [];

    if (preg_match('/^---([\s\S]*?)---/', ltrim($partContent), $matches)) {
        $frontmatter = $matches[1];
        if (preg_match('/^title:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $title = trim($m[1], " \t\n\r\0\x0B\"'");
        if (preg_match('/^date:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $date = trim($m[1], " \t\n\r\0\x0B\"'");
        if (preg_match('/^stardate:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $stardate = trim($m[1], " \t\n\r\0\x0B\"'");
        if (preg_match('/^realm_time:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $realmTime = trim($m[1], " \t\n\r\0\x0B\"'");
        if (preg_match('/^time:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $time = trim($m[1], " \t\n\r\0\x0B\"'");
        if (empty($time) && preg_match('/^start_time:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $time = trim($m[1], " \t\n\r\0\x0B\"'");
        if (preg_match('/^timezone:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $timezone = trim($m[1], " \t\n\r\0\x0B\"'");

        // Parse scalar or YAML list `image:` / `images:` and `image_alt:` / `image_alts:`
        $fmLines = explode("\n", $frontmatter);
        $activeListKey = null;
        foreach ($fmLines as $fmLine) {
            $trimmedFm = trim($fmLine);
            if ($trimmedFm === '') continue;
            if (strpos($trimmedFm, '-') === 0 && $activeListKey !== null) {
                $listVal = trim(substr($trimmedFm, 1));
                $listVal = trim($listVal, "\"' \t");
                if ($listVal !== '') {
                    if ($activeListKey === 'image' || $activeListKey === 'images') {
                        $sceneImages[] = $listVal;
                    } elseif ($activeListKey === 'image_alt' || $activeListKey === 'image_alts') {
                        $sceneImageAlts[] = $listVal;
                    }
                }
                continue;
            }
            if (strpos($trimmedFm, ':') !== false) {
                list($fKey, $fVal) = explode(':', $trimmedFm, 2);
                $fKey = trim($fKey);
                $fVal = trim($fVal);
                if ($fVal === '') {
                    $activeListKey = $fKey;
                    continue;
                }
                $activeListKey = null;
                $fVal = trim($fVal, "\"' \t");
                if (($fKey === 'image' || $fKey === 'images') && $fVal !== '') {
                    $sceneImages[] = $fVal;
                } elseif (($fKey === 'image_alt' || $fKey === 'image_alts') && $fVal !== '') {
                    $sceneImageAlts[] = $fVal;
                }
            } else {
                $activeListKey = null;
            }
        }
    }

    // Strip the individual YAML Frontmatter block from the part body first
    $partContent = preg_replace('/^---[\s\S]*?---\s*/', '', ltrim($partContent));

    // Shift in-part `##` and `###` scene headings down by 2 heading levels (`####` and `#####`)
    // so they nest cleanly beneath `### Part X` without colliding with `# Episode` or `## Chapter`.
    $partContent = preg_replace('/^(#{2,4})(\s+)/m', '##$1$2', (string)$partContent);

    if ($chapPartCount > 1 || $partNum > 1) {
        $partTitleDisplay = $title ? "Part {$partNum}: {$title}" : "Part {$partNum}";
    } else {
        $partTitleDisplay = $title ? $title : "Part {$partNum}";
    }

    $out = "### " . $partTitleDisplay . "\n\n";

    // Format and append extracted temporal metadata as visible markdown text
    if (!empty($date)) {
        $datetimeStr = "**Date:** " . $date;
        if (!empty($time)) {
            $datetimeStr .= " at " . $time;
        }
        if (!empty($timezone)) {
            $datetimeStr .= " " . $timezone;
        }
        $out .= $datetimeStr . "\n\n";
    } elseif (!empty($stardate) || !empty($realmTime)) {
        $cosmicSegments = [];
        if (!empty($stardate)) {
            $cosmicSegments[] = "**Stardate:** " . $stardate;
        }
        if (!empty($realmTime)) {
            $cosmicSegments[] = "**Harmonic Cycle:** " . $realmTime;
        }
        if (!empty($time)) {
            $cosmicSegments[] = "**Chronometer:** " . $time;
        }
        $out .= implode(" • ", $cosmicSegments) . "\n\n";
    }

    // Inject YAML frontmatter `image:` plates unless already embedded inline inside `$partContent`
    foreach ($sceneImages as $imgIdx => $sceneImage) {
        if ($sceneImage === '') continue;
        $normalizedSceneImg = str_replace('{{cms}}', '{{CDN}}', $sceneImage);
        $normalizedSceneImg = (string)preg_replace('/#.*$/', '', $normalizedSceneImg);
        if (strpos($normalizedSceneImg, '{{CDN}}') !== 0 && strpos($normalizedSceneImg, 'http') !== 0) {
            $cleanRel = ltrim($normalizedSceneImg, '/');
            if (strpos($cleanRel, 'raggiesoft-books/') !== 0 && strpos($cleanRel, 'engine-room-records/') !== 0) {
                $cleanRel = 'raggiesoft-books/images/scenes/' . $cleanRel;
            }
            $normalizedSceneImg = '{{CDN}}/' . $cleanRel;
        }
        $imgBasename = basename($normalizedSceneImg);
        // Note: check EVERY slide (`$imgIdx >= 0`) so frontmatter images already embedded inline in the body are never duplicated!
        if ($imgBasename !== '' && strpos((string)$partContent, $imgBasename) !== false) {
            continue;
        }
        $sceneImageAlt = isset($sceneImageAlts[$imgIdx]) && $sceneImageAlts[$imgIdx] !== ''
            ? $sceneImageAlts[$imgIdx]
            : (isset($sceneImageAlts[0]) && $sceneImageAlts[0] !== '' ? $sceneImageAlts[0] : $partTitleDisplay);
        $out .= "![{$sceneImageAlt}]({$normalizedSceneImg})\n\n";
    }

    $out .= trim((string)$partContent) . "\n\n";
    return $out;
}

/**
 * Renders an `e*` directory tree (`$scanDir`) with optional upper/lower `(era, chap, part)` bounds
 * so Story Crossroads trunks and inline continuations split cleanly without duplicating code.
 */
function renderEraDirectoryRange(
    string $scanDir,
    string $baseDir,
    ?array $stopAfter = null,
    ?array $startAfter = null
): string {
    $buf = '';
    $eraDirs = glob($scanDir . '/e*', GLOB_ONLYDIR) ?: [];
    sort($eraDirs);

    foreach ($eraDirs as $eraDir) {
        $eraName = basename($eraDir);
        $eraNum = intval(str_replace('e', '', $eraName));

        if ($stopAfter !== null && $eraNum > $stopAfter[0]) {
            continue;
        }
        if ($startAfter !== null && $eraNum < $startAfter[0]) {
            continue;
        }

        $eraTitle = "Era {$eraNum}";
        $eraManifest = $eraDir . '/ovab.json';
        if (file_exists($eraManifest)) {
            $meta = json_decode((string)file_get_contents($eraManifest), true);
            if (isset($meta['title'])) $eraTitle = $meta['title'];
        }

        $chapterDirs = glob($eraDir . '/c*', GLOB_ONLYDIR) ?: [];
        sort($chapterDirs);

        $eraBuf = '';
        foreach ($chapterDirs as $chapDir) {
            $chapName = basename($chapDir);
            $chapNum = intval(str_replace('c', '', $chapName));

            if ($stopAfter !== null && $eraNum === $stopAfter[0] && $chapNum > $stopAfter[1]) {
                continue;
            }
            if ($startAfter !== null && $eraNum === $startAfter[0] && $chapNum < $startAfter[1]) {
                continue;
            }

            $chapTitle = "Chapter {$chapNum}";
            $chapManifest = $chapDir . '/ovab.json';
            if (file_exists($chapManifest)) {
                $meta = json_decode((string)file_get_contents($chapManifest), true);
                if (isset($meta['title'])) $chapTitle = $meta['title'];
            }
            $cleanChapTitle = strip_tags($chapTitle);

            $partFiles = glob($chapDir . '/p*.md') ?: [];
            sort($partFiles);
            $chapPartCount = count($partFiles);

            $chapBuf = '';
            foreach ($partFiles as $partFile) {
                $partNum = intval(str_replace(['p', '.md'], '', basename($partFile)));
                if ($stopAfter !== null && $eraNum === $stopAfter[0] && $chapNum === $stopAfter[1] && $partNum > $stopAfter[2]) {
                    continue;
                }
                if ($startAfter !== null && $eraNum === $startAfter[0] && $chapNum === $startAfter[1] && $partNum <= $startAfter[2]) {
                    continue;
                }
                $chapBuf .= renderLumpedPartMarkdown($partFile, $baseDir, $chapPartCount);
            }

            if ($chapBuf !== '') {
                echo "    [Chapter] Reading: {$cleanChapTitle}\n";
                $eraBuf .= "## " . $cleanChapTitle . "\n\n" . $chapBuf;
            }
        }

        if ($eraBuf !== '') {
            echo "  Processing Era: {$eraTitle}...\n";
            $buf .= "# {$eraTitle}\n\n" . $eraBuf;
        }
    }

    return $buf;
}

// Scan for all narrative series folders inside the /books directory
$narrativeDirs = array_filter(glob($booksBaseDir . '/*') ?: [], 'is_dir');

if (empty($narrativeDirs)) {
    die("No narrative folders found in {$booksBaseDir}\n");
}

foreach ($narrativeDirs as $narrativeDir) {
    $narrativeName = basename($narrativeDir);
    $novelManifestFile = $narrativeDir . '/ovab.json';

    echo "\n========================================================\n";
    echo "Scanning Novel: {$narrativeName}\n";
    echo "========================================================\n";

    $seriesTitle = $narrativeName;
    if (file_exists($novelManifestFile)) {
        $novelMeta = json_decode((string)file_get_contents($novelManifestFile), true);
        if (isset($novelMeta['series_title'])) {
            $seriesTitle = $novelMeta['series_title'];
        } elseif (isset($novelMeta['title'])) {
            $seriesTitle = $novelMeta['title'];
        }
    }

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

    // Check for optional Story Crossroads manifest (`books/<slug>/crossroads.json`)
    // Supports ANY number of sequential or nested Story Crossroads across root `e*` and `paths/*` directories.
    $crossroadsFile = $narrativeDir . '/crossroads.json';
    $rawCxList = [];
    if (is_file($crossroadsFile)) {
        $cxDoc = json_decode((string)file_get_contents($crossroadsFile), true);
        if (!empty($cxDoc['crossroads']) && is_array($cxDoc['crossroads'])) {
            $rawCxList = $cxDoc['crossroads'];
        }
    }

    if (!empty($rawCxList)) {
        // Group Crossroads by their container directory (`""` for root `e*`, `"paths/<dir>"` for nested branches)
        $cxByContainer = [];
        foreach ($rawCxList as $cxEntry) {
            $trigRel = ltrim(str_replace('\\', '/', trim((string)($cxEntry['trigger_part'] ?? ''))), '/');
            if (preg_match('#^(?:(.+)/)?e(\d+)/c(\d+)/p(\d+)\.md$#', $trigRel, $tm)) {
                $containerKey = isset($tm[1]) ? trim($tm[1], '/') : '';
                $cxEntry['_bounds'] = [(int)$tm[2], (int)$tm[3], (int)$tm[4]];
                $cxByContainer[$containerKey][] = $cxEntry;
            }
        }
        foreach ($cxByContainer as &$group) {
            usort($group, static function ($a, $b) {
                return $a['_bounds'] <=> $b['_bounds'];
            });
        }
        unset($group);

        $renderContainerRecursive = null;
        $renderContainerRecursive = static function (
            string $containerDir,
            string $containerKey,
            ?array $initialStartAfter
        ) use (&$renderContainerRecursive, $narrativeDir, $baseDir, $cxByContainer): string {
            $cxs = $cxByContainer[$containerKey] ?? [];
            if (empty($cxs)) {
                return renderEraDirectoryRange($containerDir, $baseDir, null, $initialStartAfter);
            }

            $out = '';
            $cursorStartAfter = $initialStartAfter;

            foreach ($cxs as $cx) {
                $stopBound = $cx['_bounds'];
                $cxIdSlug = slugify((string)($cx['id'] ?? 'story-crossroads'));
                $cxAnchor = "crossroads-{$cxIdSlug}";
                $cxTitle = trim((string)($cx['title'] ?? 'Story Crossroads'));
                $cxPrompt = trim((string)($cx['prompt'] ?? ''));
                $cxOptions = is_array($cx['options'] ?? null) ? $cx['options'] : [];

                // 1. Render story chapters from `$cursorStartAfter` up through `$stopBound`
                $out .= renderEraDirectoryRange($containerDir, $baseDir, $stopBound, $cursorStartAfter);

                // 2. Render Crossroads decision menu with internal Pandoc links
                $out .= "***\n\n";
                $out .= "#### Story Crossroads — {$cxTitle} {#{$cxAnchor}}\n\n";
                if ($cxPrompt !== '') {
                    $out .= "*" . $cxPrompt . "*\n\n";
                }
                $out .= "*Select a Story Path below to jump directly to your chosen continuation:*\n\n";
                foreach ($cxOptions as $idx => $opt) {
                    $optIdSlug = slugify((string)($opt['id'] ?? ('path-' . ($idx + 1))));
                    $optLabel = trim((string)($opt['label'] ?? ('Path ' . ($idx + 1))));
                    $optSub = trim((string)($opt['subtitle'] ?? ''));
                    $num = $idx + 1;
                    $out .= "{$num}. [**{$optLabel}**](#crossroads-path-{$optIdSlug})" . ($optSub !== '' ? " — {$optSub}" : '') . "\n";
                }
                $out .= "\n***\n\n";

                // 3. Emit each `paths_dir` option immediately, and advance `$cursorStartAfter` for `inline_continuation`
                $inlineOpt = null;
                foreach ($cxOptions as $idx => $opt) {
                    $optType = trim((string)($opt['source_type'] ?? 'paths_dir'));
                    if ($optType === 'inline_continuation') {
                        $inlineOpt = $opt;
                        continue;
                    }
                    $optIdRaw = trim((string)($opt['id'] ?? ('path-' . ($idx + 1))));
                    $optIdSlug = slugify($optIdRaw);
                    $optLabel = trim((string)($opt['label'] ?? ('Path ' . ($idx + 1))));
                    $optSub = trim((string)($opt['subtitle'] ?? ''));
                    $relDir = trim(str_replace('\\', '/', trim((string)($opt['source_dir'] ?? "paths/{$optIdRaw}"))), '/');
                    $absPathDir = $narrativeDir . '/' . $relDir;

                    $out .= "# Story Path: {$optLabel} {#crossroads-path-{$optIdSlug}}\n\n";
                    if ($optSub !== '') {
                        $out .= "*" . $optSub . "*\n\n";
                    }
                    $out .= "*[← Return to the Story Crossroads](#{$cxAnchor})*\n\n";
                    if (is_dir($absPathDir)) {
                        $out .= $renderContainerRecursive($absPathDir, $relDir, null);
                    }
                    $out .= "***\n\n";
                    $out .= "*End of Story Path: {$optLabel}. [← Return to the Story Crossroads](#{$cxAnchor})*\n\n";
                }

                if ($inlineOpt !== null) {
                    $inlIdSlug = slugify((string)($inlineOpt['id'] ?? 'inline-path'));
                    $inlLabel = trim((string)($inlineOpt['label'] ?? 'Story Path'));
                    $inlSub = trim((string)($inlineOpt['subtitle'] ?? ''));
                    $out .= "# Story Path: {$inlLabel} {#crossroads-path-{$inlIdSlug}}\n\n";
                    if ($inlSub !== '') {
                        $out .= "*" . $inlSub . "*\n\n";
                    }
                    $out .= "*[← Return to the Story Crossroads](#{$cxAnchor})*\n\n";
                    $cursorStartAfter = $stopBound;
                } else {
                    $cursorStartAfter = [999999, 999999, 999999];
                }
            }

            // Render any remaining inline chapters after the last Crossroads in this container
            if ($cursorStartAfter === null || $cursorStartAfter[0] < 999999) {
                $lastCx = end($cxs);
                $lastAnchor = 'crossroads-' . slugify((string)($lastCx['id'] ?? 'story-crossroads'));
                $tail = renderEraDirectoryRange($containerDir, $baseDir, null, $cursorStartAfter);
                if ($tail !== '') {
                    $out .= $tail;
                    $out .= "***\n\n";
                    $out .= "*[← Return to the Story Crossroads](#{$lastAnchor})*\n\n";
                }
            }

            return $out;
        };

        $masterContent .= $renderContainerRecursive($narrativeDir, '', null);
    } else {
        // Standard linear novel compilation
        $masterContent .= renderEraDirectoryRange($narrativeDir, $baseDir, null, null);
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
