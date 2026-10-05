<?php
$sourceBooksDir = __DIR__ . '/books';
$narrativeDirs = array_filter(glob($sourceBooksDir . '/*'), 'is_dir');

function parseNode($node, $narrativeDir) {
    if (!is_array($node)) return;

    // Handle book titles using book_num directly
    if (isset($node['book_num']) && isset($node['book_title'])) {
        $bNum = str_pad($node['book_num'], 3, '0', STR_PAD_LEFT);
        $bDir = $narrativeDir . '/b' . $bNum;
        if (is_dir($bDir)) {
            $metaPath = $bDir . '/meta.json';
            file_put_contents($metaPath, json_encode(['title' => $node['book_title']], JSON_PRETTY_PRINT));
        }
    }

    // Handle chapter titles using the first part's file_path to accurately locate it
    if (isset($node['chap_title']) && !empty($node['parts']) && is_array($node['parts'])) {
        $firstPart = $node['parts'][0];
        if (isset($firstPart['file_path'])) {
            // e.g. "b008/c020/p001.md"
            if (preg_match('/^(b\d+)\/(c\d+)\//', $firstPart['file_path'], $m)) {
                $bDir = $m[1];
                $cDir = $m[2];
                $fullCDir = $narrativeDir . '/' . $bDir . '/' . $cDir;
                if (is_dir($fullCDir)) {
                    $metaPath = $fullCDir . '/meta.json';
                    file_put_contents($metaPath, json_encode(['title' => $node['chap_title']], JSON_PRETTY_PRINT));
                }
            }
        }
    }

    // Recurse
    foreach ($node as $key => $val) {
        if (is_array($val)) {
            parseNode($val, $narrativeDir);
        }
    }
}

foreach ($narrativeDirs as $narrativeDir) {
    $manifestFile = $narrativeDir . '/katie.json';
    if (!file_exists($manifestFile)) continue;
    
    $jsonContent = file_get_contents($manifestFile);
    $data = json_decode($jsonContent, true);
    
    if (is_array($data)) {
        parseNode($data, $narrativeDir);
        echo "Rescued meta.json for " . basename($narrativeDir) . "\n";
    }
}
?>
