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
    $condSceneImages = [];
    $condSceneImageAlts = [];

    /* Match the opening `--- ... ---` YAML frontmatter block if present. */
    if (preg_match('/^---([\s\S]*?)---/', ltrim($partContent), $matches)) {
        $frontmatter = $matches[1];
        /* Helper: Extracts a single-line YAML scalar (`key: "value"` or `key: value`) without
         * truncating on interior escaped quotes (`\"`), and unescapes `\"`, `\'`, `\$`, and `\\`. */
        $extractScalar = static function (string $fm, string $key): string {
            if (!preg_match('/^' . preg_quote($key, '/') . ':\s*(.+)$/m', $fm, $m)) {
                return '';
            }
            $val = trim($m[1]);
            if (strlen($val) >= 2 && (($val[0] === '"' && substr($val, -1) === '"') || ($val[0] === "'" && substr($val, -1) === "'"))) {
                $val = substr($val, 1, -1);
            }
            return trim(str_replace(['\"', "\\'", '\$', '\\\\'], ['"', "'", '$', '\\'], $val));
        };
        $title     = $extractScalar($frontmatter, 'title');
        $date      = $extractScalar($frontmatter, 'date');
        $stardate  = $extractScalar($frontmatter, 'stardate');
        $realmTime = $extractScalar($frontmatter, 'realm_time');
        $time      = $extractScalar($frontmatter, 'time');
        /* Fall back to `start_time:` when `time:` is blank or omitted. */
        if ($time === '') $time = $extractScalar($frontmatter, 'start_time');
        $timezone  = $extractScalar($frontmatter, 'timezone');

        /* MINI STATE-MACHINE PARSER FOR SCALARS, LISTS, & `image_by_path:` MAPS:
         * Scene files can declare either a single image (`image:`), multi-slide YAML lists (`images:` / `image_alts:`),
         * OR Story Crossroads path-aware maps (`image_by_path:` / `image_alt_by_path:`). */
        $fmLines = explode("\n", $frontmatter);
        $activeListKey = null;
        $activeMapKey = null;
        $activeMapSubKey = null;
        $imgByPathMap = [];
        $altByPathMap = [];
        foreach ($fmLines as $fmLine) {
            $isIndented = ($fmLine !== '' && ($fmLine[0] === ' ' || $fmLine[0] === "\t"));
            $trimmedFm = trim($fmLine);
            if ($trimmedFm === '' || strpos($trimmedFm, '#') === 0) continue;

            if ($isIndented && $activeMapKey !== null) {
                if (strpos($trimmedFm, '-') === 0 && $activeMapSubKey !== null) {
                    $listVal = trim(substr($trimmedFm, 1), "\"' \t");
                    if ($listVal !== '') {
                        if ($activeMapKey === 'image_by_path') $imgByPathMap[$activeMapSubKey][] = $listVal;
                        elseif ($activeMapKey === 'image_alt_by_path') $altByPathMap[$activeMapSubKey][] = $listVal;
                    }
                    continue;
                }
                if (strpos($trimmedFm, ':') !== false) {
                    list($sk, $sv) = explode(':', $trimmedFm, 2);
                    $sk = trim($sk, "\"' \t");
                    $sv = trim($sv);
                    if ($sv === '') {
                        $activeMapSubKey = $sk;
                    } else {
                        $activeMapSubKey = null;
                        $cleanSv = trim($sv, "\"' \t");
                        if ($cleanSv !== '') {
                            if ($activeMapKey === 'image_by_path') $imgByPathMap[$sk][] = $cleanSv;
                            elseif ($activeMapKey === 'image_alt_by_path') $altByPathMap[$sk][] = $cleanSv;
                        }
                    }
                    continue;
                }
            }

            /* Case 1: Indented YAML list bullet (`- "value"`) while inside `$activeListKey`. */
            if (strpos($trimmedFm, '-') === 0 && $activeListKey !== null) {
                $listVal = trim(substr($trimmedFm, 1));
                $listVal = trim($listVal, "\"' \t");
                if ($listVal !== '') {
                    if ($activeListKey === 'image' || $activeListKey === 'images') {
                        $sceneImages[] = $listVal;
                    } elseif ($activeListKey === 'image_alt' || $activeListKey === 'image_alts') {
                        $sceneImageAlts[] = $listVal;
                    } elseif ($activeListKey === 'image_when_matched') {
                        $condSceneImages[] = $listVal;
                    } elseif ($activeListKey === 'image_alt_when_matched') {
                        $condSceneImageAlts[] = $listVal;
                    }
                }
                continue;
            }
            /* Case 2: Standard `key: value` or `key:` (list/map opener) line. */
            if (strpos($trimmedFm, ':') !== false) {
                list($fKey, $fVal) = explode(':', $trimmedFm, 2);
                $fKey = trim($fKey);
                $fVal = trim($fVal);
                $activeMapKey = null;
                $activeMapSubKey = null;
                if ($fVal === '') {
                    if (in_array($fKey, ['image_by_path', 'image_alt_by_path', 'theme_by_path'], true)) {
                        $activeMapKey = $fKey;
                        $activeListKey = null;
                    } else {
                        $activeListKey = $fKey;
                    }
                    continue;
                }
                $activeListKey = null;
                $fVal = trim($fVal, "\"' \t");
                if (($fKey === 'image' || $fKey === 'images') && $fVal !== '') {
                    $sceneImages[] = $fVal;
                } elseif (($fKey === 'image_alt' || $fKey === 'image_alts') && $fVal !== '') {
                    $sceneImageAlts[] = $fVal;
                } elseif ($fKey === 'image_when_matched' && $fVal !== '') {
                    $condSceneImages[] = $fVal;
                } elseif ($fKey === 'image_alt_when_matched' && $fVal !== '') {
                    $condSceneImageAlts[] = $fVal;
                }
            } else {
                $activeListKey = null;
                $activeMapKey = null;
                $activeMapSubKey = null;
            }
        }
        foreach ($imgByPathMap as $bpKey => $bpUrls) {
            $bpAlts = $altByPathMap[$bpKey] ?? [];
            foreach ($bpUrls as $bpIdx => $bpUrl) {
                $condSceneImages[] = $bpUrl;
                $condSceneImageAlts[] = $bpAlts[$bpIdx] ?? ($bpAlts[0] ?? '');
            }
        }
    }

    /* Remove the individual part's `--- ... ---` YAML frontmatter block so Pandoc
     * doesn't mistake mid-book frontmatter blocks for horizontal rules or metadata resets. */
    $rawFmBlock = isset($frontmatter) ? (string)$frontmatter : '';
    $partContent = preg_replace('/^---[\s\S]*?---\s*/', '', ltrim($partContent));

    /* IMMUTABLE SEPTEMBER 11, 2001 ZERO-IMAGES FATAL GUARDRAIL (`lump_narratives.php`):
     * Every story section across `raggiesoft-narratives` set on September 11, 2001 MUST
     * remain 100% un-illustrated. If any YAML image key (`image`, `images`, `image_by_path`,
     * `image_when_matched`, `og_image`, `ogImage`), inline Markdown `![...](...)`, or `<img`
     * tag appears on a 9/11 part, abort immediately with exit code `1`! */
    if ($date !== '' && preg_match('/(?:\b2001-0?9-11\b|\b0?9[\/\-]11[\/\-]2001\b|\bSept(?:ember)?\.?\s+11(?:th)?,?\s+2001\b|\b11\s+Sept(?:ember)?\.?,?\s+2001\b)/i', $date)) {
        $hasFmImg = !empty($sceneImages) || !empty($condSceneImages) || (bool)preg_match('/^(?:image|images|image_by_path|image_when_matched|og_image|ogImage):\s*(?:[^\s#][^\r\n]*|\r?\n\s*(?:-\s*\S+|[A-Za-z0-9_-]+:))/mi', $rawFmBlock);
        $bodyNoCode = (string)preg_replace('/```[\s\S]*?```/', '', (string)$partContent);
        $hasBodyImg = (bool)preg_match('/!\[[^\]]*\]\([^)]+\)|<img\b/i', $bodyNoCode);
        if ($hasFmImg || $hasBodyImg) {
            fwrite(
                STDERR,
                "\n🛑 FATAL GUARDRAIL ERROR [IMMUTABLE SEPTEMBER 11, 2001 ZERO-IMAGES RULE]:\n" .
                "   File: {$partFile}\n" .
                "   Date: {$date}\n" .
                "   September 11, 2001 story sections MUST NEVER contain rendered images. Aborting lump build.\n\n"
            );
            exit(1);
        }
    }

    /* EXPAND ROUTE-CONDITIONAL DIRECTIVES (`<!-- @if-path: ... -->` / `<!-- @if-not-path: ... -->` / `<!-- @else -->` / `<!-- @endif -->`)
     * FOR STATIC EPUB & PDF VOLUMES:
     * Static EPUB/PDF eBooks compile all coequal Story Crossroads routes into one volume without browser cookies.
     * Expanding conditional blocks into the default passage plus a clearly labeled Story Path callout—and
     * embedding any `image_by_path:` illustration once inside the alternate block—preserves 100% of prose & art! */
    if (strpos((string)$partContent, '@if-path:') !== false || strpos((string)$partContent, '@if-not-path:') !== false || strpos((string)$partContent, ':::if-path[') !== false) {
        $condImgMarkdownPrefix = '';
        foreach ($condSceneImages as $cIdx => $cSceneImg) {
            if ($cSceneImg === '') continue;
            $normCondImg = (string)preg_replace('/#.*$/', '', str_replace('{{cms}}', '{{CDN}}', $cSceneImg));
            if (strpos($normCondImg, '{{CDN}}') !== 0 && strpos($normCondImg, 'http') !== 0) {
                $cRel = ltrim($normCondImg, '/');
                if (strpos($cRel, 'raggiesoft-books/') !== 0 && strpos($cRel, 'engine-room-records/') !== 0) {
                    $cRel = 'raggiesoft-books/images/scenes/' . $cRel;
                }
                $normCondImg = '{{CDN}}/' . $cRel;
            }
            $cBasename = basename($normCondImg);
            if ($cBasename !== '' && strpos((string)$partContent, $cBasename) === false) {
                $cAlt = $condSceneImageAlts[$cIdx] ?? ($condSceneImageAlts[0] ?? $title);
                $condImgMarkdownPrefix .= "![{$cAlt}]({$normCondImg})\n\n";
            }
        }
        $injectedCondImgOnce = false;
        $formatEbookCondBlock = static function (bool $isNot, string $rawExpr, string $thenBranch, string $elseBranch) use (&$injectedCondImgOnce, $condImgMarkdownPrefix): string {
            $defaultBody = $isNot ? $thenBranch : $elseBranch;
            $variantBody = $isNot ? $elseBranch : $thenBranch;
            $cleanExpr   = trim(ltrim($rawExpr, '!'));
            $humanRoute  = ($cleanExpr === 'seattle-full-exodus-rescue')
                ? 'If continuing from the Full Seattle Pathfinder Route'
                : ('If continuing from Story Path: ' . ucwords(str_replace(['-', '_'], ' ', $cleanExpr)));
            $elseLabel   = ($cleanExpr === 'seattle-full-exodus-rescue')
                ? 'On all other Earth routes'
                : 'On other Story Paths';
            $condPlate = '';
            if (!$injectedCondImgOnce && $condImgMarkdownPrefix !== '' && $variantBody !== '') {
                $condPlate = $condImgMarkdownPrefix;
                $injectedCondImgOnce = true;
            }
            if ($defaultBody !== '' && $variantBody !== '') {
                return "*[{$elseLabel}:]*\n\n{$defaultBody}\n\n* * *\n\n*[{$humanRoute}:]*\n\n{$condPlate}{$variantBody}\n\n";
            }
            if ($defaultBody !== '') {
                return "{$defaultBody}\n\n";
            }
            return "*[{$humanRoute}:]*\n\n{$condPlate}{$variantBody}\n\n";
        };

        $partContent = (string)preg_replace_callback(
            '/^[ \t]*<!--[ \t]*@(if-path|if-not-path):\s*([^>]+?)[ \t]*-->[ \t]*\R([\s\S]*?)(?:^[ \t]*<!--[ \t]*@else[ \t]*-->[ \t]*\R([\s\S]*?))?^[ \t]*<!--[ \t]*@endif[ \t]*-->[ \t]*\R?/m',
            static fn(array $m) => $formatEbookCondBlock(
                strtolower(trim((string)$m[1])) === 'if-not-path',
                trim((string)$m[2]),
                trim((string)$m[3]),
                isset($m[4]) ? trim((string)$m[4]) : ''
            ),
            (string)$partContent
        );
        $partContent = (string)preg_replace_callback(
            '/^[ \t]*:::if-path\[([^\]]+)\][ \t]*\R([\s\S]*?)(?:^[ \t]*:::else[ \t]*\R([\s\S]*?))?^[ \t]*:::endif[ \t]*\R?/m',
            static fn(array $m) => $formatEbookCondBlock(
                false,
                trim((string)$m[1]),
                trim((string)$m[2]),
                isset($m[3]) ? trim((string)$m[3]) : ''
            ),
            (string)$partContent
        );
    }

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

        /* Discover any Diamond Convergence (`"rejoin_part": "e001/c002/p001.md"`) coordinates so
         * converged `paths_dir` branches link straight forward into the shared continuation instead of
         * duplicating post-convergence chapters! */
        $rejoinBoundsByRel = [];
        foreach ($rawCxList as $cxScan) {
            foreach (($cxScan['options'] ?? []) as $optScan) {
                $rjRel = ltrim(str_replace('\\', '/', trim((string)($optScan['rejoin_part'] ?? ''))), '/');
                if ($rjRel !== '' && preg_match('#^e(\d+)/c(\d+)/p(\d+)\.md$#', $rjRel, $rm)) {
                    $rejoinBoundsByRel[$rjRel] = [
                        'bounds' => [(int)$rm[1], (int)$rm[2], (int)$rm[3]],
                        'anchor' => 'crossroads-rejoin-' . slugify(str_replace(['/', '.md'], ['-', ''], $rjRel)),
                    ];
                }
            }
        }

        /* 100-LEVEL PHP LESSON — RECURSIVE ANONYMOUS CLOSURE:
         * Assigning `$renderContainerRecursive = null;` first and capturing it by
         * reference (`use (&$renderContainerRecursive, ...)`) lets this closure call
         * ITSELF when rendering nested `paths/<route>` folders! */
        $renderContainerRecursive = null;
        $renderContainerRecursive = static function (
            string $containerDir,
            string $containerKey,
            ?array $initialStartAfter
        ) use (&$renderContainerRecursive, $narrativeDir, $baseDir, $cxByContainer, $rejoinBoundsByRel): string {
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

                /* If we are in the root container (`""`) and a diamond-convergence `rejoin_part` lies
                 * strictly between `$cursorStartAfter` and `$stopBound`, emit the pre-rejoin inline chapters
                 * first, place the `{#crossroads-rejoin-...}` convergence banner right before `rejoin_part`,
                 * and then continue smoothly up to `$stopBound`! */
                if ($containerKey === '' && $cursorStartAfter !== null && !empty($rejoinBoundsByRel)) {
                    foreach ($rejoinBoundsByRel as $rjInfo) {
                        $rjb = $rjInfo['bounds'];
                        if ($rjb > $cursorStartAfter && $rjb <= $stopBound) {
                            /* Inclusive upper bound right before `[rEra, rChap, rPart]`:
                             * if `rPart > 1`, stop at `[rEra, rChap, rPart - 1]`; else if `rChap > 1`,
                             * stop at `[rEra, rChap - 1, 999999]`; else `[rEra - 1, 999999, 999999]`. */
                            $preRjStop = $rjb[2] > 1
                                ? [$rjb[0], $rjb[1], $rjb[2] - 1]
                                : ($rjb[1] > 1 ? [$rjb[0], $rjb[1] - 1, 999999] : [$rjb[0] - 1, 999999, 999999]);
                            $out .= renderEraDirectoryRange($containerDir, $baseDir, $preRjStop, $cursorStartAfter);
                            $out .= "***\n\n";
                            $out .= "#### All Earlier Story Paths Converge Here {#" . $rjInfo['anchor'] . "}\n\n";
                            $out .= "***\n\n";
                            $cursorStartAfter = $preRjStop;
                        }
                    }
                }

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

                /* 3. Emit each `paths_dir` Story Path section (with return links back to `#$cxAnchor`
                 *    plus forward convergence jump links when `"rejoin_part"` is set!),
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
                    $optRejoinRel = ltrim(str_replace('\\', '/', trim((string)($opt['rejoin_part'] ?? ''))), '/');
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
                    if ($optRejoinRel !== '' && isset($rejoinBoundsByRel[$optRejoinRel])) {
                        $rjAnc = $rejoinBoundsByRel[$optRejoinRel]['anchor'];
                        $out .= "*Story Path Converges — [Continue to the Shared Storyline →](#{$rjAnc}) • [← Return to the Story Crossroads](#{$cxAnchor})*\n\n";
                    } else {
                        $out .= "*End of Story Path: {$optLabel}. [← Return to the Story Crossroads](#{$cxAnchor})*\n\n";
                    }
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
