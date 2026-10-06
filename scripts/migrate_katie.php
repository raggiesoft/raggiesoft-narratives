<?php
$sourceBooksDir = dirname(__DIR__) . '/books';
$narrativeDirs = array_filter(glob($sourceBooksDir . '/*'), 'is_dir');

foreach ($narrativeDirs as $narrativeDir) {
    $manifestFile = $narrativeDir . '/katie.json';
    if (!file_exists($manifestFile)) continue;
    
    $katie = json_decode(file_get_contents($manifestFile), true);
    if (json_last_error() !== JSON_ERROR_NONE) continue;
    
    $legacyBooks = isset($katie['books']) ? $katie['books'] : (isset($katie[0]) ? $katie : []);
    
    foreach ($legacyBooks as $bIndex => $book) {
        if (!isset($book['book_title'])) continue;
        
        $bNum = str_pad($bIndex + 1, 3, '0', STR_PAD_LEFT);
        if (isset($book['book_num'])) $bNum = str_pad($book['book_num'], 3, '0', STR_PAD_LEFT);
        
        $bDir = $narrativeDir . '/b' . $bNum;
        if (is_dir($bDir)) {
            $metaPath = $bDir . '/ovab.json';
            if (!file_exists($metaPath)) {
                file_put_contents($metaPath, json_encode(['title' => $book['book_title']], JSON_PRETTY_PRINT));
            }
        }
        
        if (!isset($book['chapters'])) continue;
        
        foreach ($book['chapters'] as $cIndex => $chap) {
            if (!isset($chap['chap_title'])) continue;
            
            $cNum = str_pad($cIndex + 1, 3, '0', STR_PAD_LEFT);
            if (isset($chap['chap_num'])) $cNum = str_pad($chap['chap_num'], 3, '0', STR_PAD_LEFT);
            
            $cDir = $bDir . '/c' . $cNum;
            if (is_dir($cDir)) {
                $metaPath = $cDir . '/ovab.json';
                if (!file_exists($metaPath)) {
                    file_put_contents($metaPath, json_encode(['title' => $chap['chap_title']], JSON_PRETTY_PRINT));
                }
            }
        }
    }
    echo "Migrated katie.json titles to ovab.json for " . basename($narrativeDir) . "\n";
}
?>
