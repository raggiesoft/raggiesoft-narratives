<?php
/**
 * ============================================================================
 * READ-ALONG TEACHING OVERVIEW: lump_narratives.php
 * ============================================================================
 * WHAT THIS FILE IS:
 *   The manuscript assembler ("AI Context & eBook Lumper") for Ocean View
 *   Archives and RaggieSoft Story Crossroads. Authors write novels as modular
 *   scene files (`books/<series>/e001/c001/p001.md`) so chapters are easy to
 *   edit and render on `books.raggiesoft.com`. This CLI PHP script walks every
 *   series directory, stitches all Eras (`e*`), Chapters (`c*`), and Parts
 *   (`p*.md`)—plus any branching Story Crossroads routes (`crossroads.json`)—
 *   into one complete novel Markdown manuscript per series.
 *
 * HOW IT IS RUN:
 *   Executed from the terminal (or automatically upstream of `compile_ebooks.php`
 *   inside `paige.php` / `jenna-sync.sh`):
 *     `php raggiesoft-narratives/scripts/lump_narratives.php`
 *   (Strictly keeps `<?php` on Line 1 for PHP CLI compatibility.)
 *
 * WHAT INPUTS IT CONSUMES:
 *   1. `raggiesoft-narratives/books/<series>/ovab.json` (series/era/chapter titles).
 *   2. `raggiesoft-narratives/books/<series>/e<NNN>/c<NNN>/p<NNN>.md` (individual scene parts
 *      with YAML frontmatter `title`, `date`, `time`/`start_time`, `timezone`,
 *      `stardate`, `realm_time`, `image`/`images`, `image_alt`/`image_alts`).
 *   3. Optional `raggiesoft-narratives/books/<series>/crossroads.json` defining
 *      coequal RaggieSoft Story Crossroads interactive branching narratives
 *      (`trigger_part`, `options`, `paths/<option-id>/e<NNN>/c<NNN>/p<NNN>.md`, or `inline_continuation`).
 *
 * WHAT COMPILED OUTPUT IT PRODUCES:
 *   - Writes `raggiesoft-narratives/books-ai-lump/<series>/<title-slug>-complete.md`
 *     with normalized Pandoc heading hierarchy (`# Era`, `## Chapter`, `### Part`,
 *     `#### Scene`), embedded scene illustration Markdown tags, clickable internal
 *     `{#crossroads-...}` / `{#crossroads-path-...}` Story Crossroads jump menus,
 *     and MD5 change detection so unchanged novels keep their existing file `mtime`!
 *
 * 100-LEVEL PHP CLI CONCEPTS TAUGHT IN THIS FILE:
 *   - Line-by-Line State Machine Parsing (`$activeListKey`) for YAML lists vs scalars.
 *   - Heading Level Shifting (`preg_replace('/^(#{2,4})(\s+)/m', '##$1$2', ...)`).
 *   - Tuple Range Filtering (`[era, chap, part]` upper/lower bounds) + PHP's
 *     "Spaceship" comparison operator (`<=>`) for sorting multi-part coordinates.
 *   - Recursive Anonymous Functions (`use (&$renderContainerRecursive, ...)`):
 *     How a closure calls itself to support nested Story Crossroads of any depth!
 * ============================================================================
 */

echo "Starting AI Context Lumper...\n";
echo "Validating directories...\n";

/* Establish base paths relative to `raggiesoft-narratives/scripts` (`__DIR__`). */
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

/**
 * Helper: Converts a human book/path title (e.g. `"Escape Velocity: Ad Astra"`)
 * into a URL- and filename-safe lowercase hyphenated slug (`"escape-velocity-ad-astra"`).
 */
function slugify($string) {
    /* Replace any run of non-alphanumeric characters with `-`, trim edge `-`, and lowercase. */
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string), '-'));
    /* Collapse any multiple consecutive hyphens `--` into a single `-`. */
    return preg_replace('/-+/', '-', $slug);
}

/**
 * ============================================================================
 * HELPER 1: `renderLumpedPartMarkdown()`
 * ============================================================================
 * Reads one `pXXX.md` scene file, extracts its YAML frontmatter metadata
 * (title, Earth or cosmic timestamps, and scene illustrations), strips the raw
 * YAML block, shifts internal headings down so they nest cleanly under `### Part`,
 * and returns a self-contained Markdown section ready for Pandoc compilation.
 *
 * @param string $partFile      Absolute path to `.../e001/c001/p001.md`.
 * @param string $baseDir       Path to `raggiesoft-narratives` (for clean console logs).
 * @param int    $chapPartCount Total number of `p*.md` files in the parent chapter.
 * @return string Consolidated Markdown text for this Part.
 */
function renderLumpedPartMarkdown(string $partFile, string $baseDir, int $chapPartCount): string {
    /* Extract integer part number from filename: `'p003.md'` -> `'003'` -> `3`. */
    $partName = basename($partFile);
    $partNum = intval(str_replace(['p', '.md'], '', $partName));

    $relPath = str_replace($baseDir . '/', '', $partFile);
    echo "      -> [Part] Appending: {$relPath}\n";

    $partContent = (string)file_get_contents($partFile);

    /* Metadata accumulators populated from YAML frontmatter (`--- ... ---`). */
    $title = '';
    $date = '';
    $stardate = '';
    $realmTime = '';
    $time = '';
    $timezone = '';
    $sceneImages = [];
    $sceneImageAlts = [];

    /* Match the opening `--- ... ---` YAML frontmatter block if present. */
    if (preg_match('/^---([\s\S]*?)---/', ltrim($partContent), $matches)) {
        $frontmatter = $matches[1];
        /* Extract single-line scalar YAML fields using `/m` (multiline `^` anchor). */
        if (preg_match('/^title:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $title = trim($m[1], " \t\n\r\0\x0B\"'");
        if (preg_match('/^date:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $date = trim($m[1], " \t\n\r\0\x0B\"'");
        if (preg_match('/^stardate:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $stardate = trim($m[1], " \t\n\r\0\x0B\"'");
        if (preg_match('/^realm_time:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $realmTime = trim($m[1], " \t\n\r\0\x0B\"'");
        if (preg_match('/^time:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $time = trim($m[1], " \t\n\r\0\x0B\"'");
        /* Fall back to `start_time:` when `time:` is blank or omitted. */
        if (empty($time) && preg_match('/^start_time:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $time = trim($m[1], " \t\n\r\0\x0B\"'");
        if (preg_match('/^timezone:\s*"?([^"\r\n]+)"?/m', $frontmatter, $m)) $timezone = trim($m[1], " \t\n\r\0\x0B\"'");

        /* MINI STATE-MACHINE PARSER FOR SCALAR OR MULTI-IMAGE YAML LISTS:
         * Scene files can declare either a single image:
         *   `image: "{{CDN}}/raggiesoft-books/images/scenes/foo.jpg"`
         * OR a multi-slide YAML list:
         *   `images:`
         *   `  - "{{CDN}}/.../slide1.jpg"`
         *   `  - "{{CDN}}/.../slide2.jpg"`
         * `$activeListKey` remembers which YAML key (`images`, `image_alts`, etc.)
         * opened an empty-value header line so subsequent `- item` lines append to the right array! */
        $fmLines = explode("\n", $frontmatter);
        $activeListKey = null;
        foreach ($fmLines as $fmLine) {
            $trimmedFm = trim($fmLine);
            if ($trimmedFm === '') continue;
            /* Case 1: Indented YAML list bullet (`- "value"`) while inside `$activeListKey`. */
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
            /* Case 2: Standard `key: value` or `key:` (list opener) line. */
            if (strpos($trimmedFm, ':') !== false) {
                list($fKey, $fVal) = explode(':', $trimmedFm, 2);
                $fKey = trim($fKey);
                $fVal = trim($fVal);
                if ($fVal === '') {
                    /* Empty value after `:` means a YAML list starts on the next line! */
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

    /* Remove the individual part's `--- ... ---` YAML frontmatter block so Pandoc
     * doesn't mistake mid-book frontmatter blocks for horizontal rules or metadata resets. */
    $partContent = preg_replace('/^---[\s\S]*?---\s*/', '', ltrim($partContent));

    /* HEADING HIERARCHY SHIFT:
     * In the lumped novel:
     *   `#`    (H1) = Era / Story Path
     *   `##`   (H2) = Chapter
     *   `###`  (H3) = Part
     * Therefore, any `## Scene` or `### Subscene` inside a Part file is prepended
     * with two extra `##` hashes (`####` / `#####`) so Pandoc's 3-level TOC stays clean! */
    $partContent = preg_replace('/^(#{2,4})(\s+)/m', '##$1$2', (string)$partContent);

    /* Multi-part chapters show `"Part 1: Title"`; single-part chapters show `"Title"` cleanly. */
    if ($chapPartCount > 1 || $partNum > 1) {
        $partTitleDisplay = $title ? "Part {$partNum}: {$title}" : "Part {$partNum}";
    } else {
        $partTitleDisplay = $title ? $title : "Part {$partNum}";
    }

    $out = "### " . $partTitleDisplay . "\n\n";

    /* Emit visible dateline metadata below the `### Part` heading:
     * - Contemporary / Earth stories use `Date: ... at ... Timezone`.
     * - Sci-Fi / Fantasy stories (`Ad Astra`, `Aethel`) use `Stardate` / `Harmonic Cycle` / `Chronometer`. */
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

    /* Embed YAML frontmatter scene plate images (`![alt]({{CDN}}/...)`) right above
     * the scene prose—SKIPPING any image whose filename is already embedded inline
     * inside `$partContent` so an illustration never prints twice in the same scene! */
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
 * ============================================================================
 * HELPER 2: `renderEraDirectoryRange()`
 * ============================================================================
 * Walks `e* -> c* -> p*.md` inside `$scanDir` in sorted numerical order and
 * concatenates `# Era`, `## Chapter`, and `### Part` Markdown.
 *
 * Why `$stopAfter` and `$startAfter` `[era, chap, part]` 3-element integer tuples?
 * When a Story Crossroads splits a book after e.g. `e002/c004/p002.md` (`[2, 4, 2]`):
 *   - First call passes `$stopAfter = [2, 4, 2]` to emit everything UP THROUGH `e002/c004/p002.md`.
 *   - Later call passes `$startAfter = [2, 4, 2]` to emit any inline continuation AFTER `e002/c004/p002.md`!
 *   - Linear novels pass `null, null` to emit 100% of chapters from start to finish.
 */
function renderEraDirectoryRange(
    string $scanDir,
    string $baseDir,
    ?array $stopAfter = null,
    ?array $startAfter = null
): string {
    $buf = '';
    /* `GLOB_ONLYDIR` returns only subdirectories matching `e*` (e.g. `e001`, `e002`). */
    $eraDirs = glob($scanDir . '/e*', GLOB_ONLYDIR) ?: [];
    sort($eraDirs);

    foreach ($eraDirs as $eraDir) {
        $eraName = basename($eraDir);
        $eraNum = intval(str_replace('e', '', $eraName));

        /* Skip entire Era folders outside our requested `[era, chap, part]` window. */
        if ($stopAfter !== null && $eraNum > $stopAfter[0]) {
            continue;
        }
        if ($startAfter !== null && $eraNum < $startAfter[0]) {
            continue;
        }

        /* Read human-friendly Era title from `eXXX/ovab.json` if present. */
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

            /* Skip Chapters outside our requested `[era, chap, part]` window. */
            if ($stopAfter !== null && $eraNum === $stopAfter[0] && $chapNum > $stopAfter[1]) {
                continue;
            }
            if ($startAfter !== null && $eraNum === $startAfter[0] && $chapNum < $startAfter[1]) {
                continue;
            }

            /* Read human-friendly Chapter title from `cXXX/ovab.json` (stripping any HTML tags). */
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
                /* Inclusive upper bound (`> $stopAfter[2]` skipped) and exclusive lower bound (`<= $startAfter[2]` skipped). */
                if ($stopAfter !== null && $eraNum === $stopAfter[0] && $chapNum === $stopAfter[1] && $partNum > $stopAfter[2]) {
                    continue;
                }
                if ($startAfter !== null && $eraNum === $startAfter[0] && $chapNum === $startAfter[1] && $partNum <= $startAfter[2]) {
                    continue;
                }
                $chapBuf .= renderLumpedPartMarkdown($partFile, $baseDir, $chapPartCount);
            }

            /* Only emit the `## Chapter` heading if at least 1 Part matched inside this range! */
            if ($chapBuf !== '') {
                echo "    [Chapter] Reading: {$cleanChapTitle}\n";
                $eraBuf .= "## " . $cleanChapTitle . "\n\n" . $chapBuf;
            }
        }

        /* Only emit the `# Era` heading if at least 1 Chapter matched inside this range! */
        if ($eraBuf !== '') {
            echo "  Processing Era: {$eraTitle}...\n";
            $buf .= "# {$eraTitle}\n\n" . $eraBuf;
        }
    }

    return $buf;
}

/* ============================================================================
 * STEP 3: DISCOVER ALL NOVEL SERIES IN `raggiesoft-narratives/books/` & LUMP
 * ============================================================================ */
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

    /* Resolve canonical series display title from `books/<slug>/ovab.json`. */
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

    /* Top-level Pandoc YAML frontmatter setting the eBook's `<dc:title>` metadata. */
    $masterContent = "---\n";
    $masterContent .= "title: \"{$seriesTitle}\"\n";
    $masterContent .= "---\n\n";

    /* ========================================================================
     * STEP 4: CHECK FOR RAGGIESOFT STORY CROSSROADS (`crossroads.json`)
     * ========================================================================
     * Interactive Story Crossroads novels define one or more branching decision
     * points in `crossroads.json`. For offline EPUB/PDF readers (where cookies
     * and JS modals don't exist), we compile ALL coequal routes into one volume
     * connected by clickable Pandoc internal anchor links (`{#crossroads-...}`)!
     */
    $crossroadsFile = $narrativeDir . '/crossroads.json';
    $rawCxList = [];
    if (is_file($crossroadsFile)) {
        $cxDoc = json_decode((string)file_get_contents($crossroadsFile), true);
        if (!empty($cxDoc['crossroads']) && is_array($cxDoc['crossroads'])) {
            $rawCxList = $cxDoc['crossroads'];
        }
    }

    if (!empty($rawCxList)) {
        /* Group Crossroads definitions by container folder:
         *   - `""` (empty string) for Crossroads triggered inside the main trunk `e001/c002/p003.md`.
         *   - `"paths/<route>"` for nested Crossroads triggered inside an already-branched Story Path! */
        $cxByContainer = [];
        foreach ($rawCxList as $cxEntry) {
            $trigRel = ltrim(str_replace('\\', '/', trim((string)($cxEntry['trigger_part'] ?? ''))), '/');
            if (preg_match('#^(?:(.+)/)?e(\d+)/c(\d+)/p(\d+)\.md$#', $trigRel, $tm)) {
                $containerKey = isset($tm[1]) ? trim($tm[1], '/') : '';
                $cxEntry['_bounds'] = [(int)$tm[2], (int)$tm[3], (int)$tm[4]];
                $cxByContainer[$containerKey][] = $cxEntry;
            }
        }
        /* Sort Crossroads inside each container chronologically using PHP's `<=>`
         * "spaceship" operator (compares `[era, chap, part]` arrays element-by-element!). */
        foreach ($cxByContainer as &$group) {
            usort($group, static function ($a, $b) {
                return $a['_bounds'] <=> $b['_bounds'];
            });
        }
        unset($group);

        /* 100-LEVEL PHP LESSON — RECURSIVE ANONYMOUS CLOSURE:
         * Assigning `$renderContainerRecursive = null;` first and capturing it by
         * reference (`use (&$renderContainerRecursive, ...)`) lets this closure call
         * ITSELF when rendering nested `paths/<route>` folders! */
        $renderContainerRecursive = null;
        $renderContainerRecursive = static function (
            string $containerDir,
            string $containerKey,
            ?array $initialStartAfter
        ) use (&$renderContainerRecursive, $narrativeDir, $baseDir, $cxByContainer): string {
            $cxs = $cxByContainer[$containerKey] ?? [];
            /* Base case: no Crossroads inside this directory -> render all remaining chapters linearly. */
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

                /* 1. Emit shared story chapters up through the `trigger_part` scene (`$stopBound`). */
                $out .= renderEraDirectoryRange($containerDir, $baseDir, $stopBound, $cursorStartAfter);

                /* 2. Inject the interactive Story Crossroads jump menu using Pandoc `{#anchor}` syntax. */
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

                /* 3. Emit each `paths_dir` Story Path section (with return links back to `#$cxAnchor`),
                 *    and remember if one option uses `inline_continuation` in the current folder. */
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

                /* If an option continues inline inside `$containerDir`, emit its `# Story Path:`
                 * banner and advance `$cursorStartAfter = $stopBound`; otherwise set a sentinel
                 * `[999999, 999999, 999999]` so no trailing chapters are emitted after pure `paths_dir` forks. */
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

            /* 4. Emit any remaining `inline_continuation` chapters after the final Crossroads in this container. */
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
        /* Standard linear novel (no `crossroads.json`): emit every Era, Chapter, and Part sequentially. */
        $masterContent .= renderEraDirectoryRange($narrativeDir, $baseDir, null, null);
    }

    /* ========================================================================
     * STEP 5: CONTENT-HASH WRITE GUARD (PRESERVES `mtime` FOR `compile_ebooks.php`)
     * ========================================================================
     * Compare the MD5 checksum of the existing `-complete.md` on disk against
     * `$masterContent` in memory. If identical, skip `file_put_contents()` so
     * unchanged novels don't trigger unnecessary Pandoc EPUB/PDF rebuilds!
     */
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
