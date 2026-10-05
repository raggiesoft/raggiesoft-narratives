<?php
$sourceBooksDir = __DIR__ . '/books';
$narrativeDirs = array_filter(glob($sourceBooksDir . '/*'), 'is_dir');

function parseNode($node, $narrativeDir) {
    if (!is_array($node)) return;

    if (isset($node['book_num']) && isset($node['book_title'])) {
        $bNum = str_pad($node['book_num'], 3, '0', STR_PAD_LEFT);
        $bDir = $narrativeDir . '/b' . $bNum;
        if (is_dir($bDir)) {
            $metaPath = $bDir . '/meta.json';
            if (!file_exists($metaPath)) {
                file_put_contents($metaPath, json_encode(['title' => $node['book_title']], JSON_PRETTY_PRINT));
                echo "Created $metaPath\n";
            }
        }
    }

    if (isset($node['chap_title']) && !empty($node['parts']) && is_array($node['parts'])) {
        $firstPart = $node['parts'][0];
        if (isset($firstPart['file_path'])) {
            if (preg_match('/^(b\d+)\/(c\d+)\//', $firstPart['file_path'], $m)) {
                $bDir = $m[1];
                $cDir = $m[2];
                $fullCDir = $narrativeDir . '/' . $bDir . '/' . $cDir;
                if (is_dir($fullCDir)) {
                    $metaPath = $fullCDir . '/meta.json';
                    if (!file_exists($metaPath)) {
                        file_put_contents($metaPath, json_encode(['title' => $node['chap_title']], JSON_PRETTY_PRINT));
                        echo "Created $metaPath\n";
                    }
                }
            }
        }
    }

    foreach ($node as $key => $val) {
        if (is_array($val)) {
            parseNode($val, $narrativeDir);
        }
    }
}

foreach ($narrativeDirs as $narrativeDir) {
    $bookSlug = basename($narrativeDir);
    // Find the latest commit that touched this file where it had "book_title"
    // Actually, let's just search the whole git history for this katie.json file
    $gitLogCmd = "cd " . escapeshellarg(__DIR__) . " && git log --format=%H -- books/{$bookSlug}/katie.json";
    exec($gitLogCmd, $commits);
    
    foreach ($commits as $commit) {
        $fileCmd = "cd " . escapeshellarg(__DIR__) . " && git show {$commit}:books/{$bookSlug}/katie.json 2>/dev/null";
        $content = shell_exec($fileCmd);
        if ($content) {
            $data = json_decode($content, true);
            if (is_array($data)) {
                // To be safe, parse every valid katie.json version we find
                // parseNode checks if meta.json is missing before writing
                parseNode($data, $narrativeDir);
            }
        }
    }
}
echo "Done rescuing missing meta.json files from Git history.\n";
?>
