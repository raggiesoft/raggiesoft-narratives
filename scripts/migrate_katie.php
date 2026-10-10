<?php
/**
 * =============================================================================
 * Architecture & Maintenance Guide: migrate_katie.php
 * =============================================================================
 * Purpose:
 *     This script is designed to migrate legacy book and chapter metadata
 *     (titles) from a centralized JSON manifest (`katie.json`) into 
 *     distributed, directory-specific JSON manifests (`ovab.json`).
 *
 * Design Principles:
 *     - Decentralization: Moves metadata closer to the content files (e.g., 
 *       placing `ovab.json` directly within `b001` or `c001` folders).
 *     - Non-Destructive: Checks for the existence of `ovab.json` before 
 *       writing, ensuring any manual overrides or existing files are preserved.
 *     - Flexible Padding: Converts index or numeric keys to zero-padded 
 *       directory names (e.g., `b001`, `c001`) to match the filesystem structure.
 *
 * Maintenance Notes:
 *     - If the directory naming convention changes (e.g., dropping the 'b' or 'c' 
 *       prefix, or changing padding), update the `$bNum` and `$cNum` logic.
 *     - The script skips directories missing a `katie.json` manifest.
 * =============================================================================
 */

// Define the root source directory for narrative books
$sourceBooksDir = dirname(__DIR__) . '/books';

// Retrieve all top-level narrative directories, filtering out non-directories
$narrativeDirs = array_filter(glob($sourceBooksDir . '/*'), 'is_dir');

foreach ($narrativeDirs as $narrativeDir) {
    $manifestFile = $narrativeDir . '/katie.json';
    
    // Skip narratives that lack the legacy centralized manifest
    if (!file_exists($manifestFile)) continue;
    
    // Decode the legacy manifest file into an associative array
    $katie = json_decode(file_get_contents($manifestFile), true);
    
    // Skip if the JSON is malformed
    if (json_last_error() !== JSON_ERROR_NONE) continue;
    
    // Extract the books array, handling varying legacy structures (nested or root-level array)
    $legacyBooks = isset($katie['books']) ? $katie['books'] : (isset($katie[0]) ? $katie : []);
    
    foreach ($legacyBooks as $bIndex => $book) {
        // Skip entries missing a title, as there's nothing to migrate
        if (!isset($book['book_title'])) continue;
        
        // Determine the book number: use the array index (+1) or an explicit 'book_num' if available
        // Pad the number to 3 digits (e.g., '001')
        $bNum = str_pad($bIndex + 1, 3, '0', STR_PAD_LEFT);
        if (isset($book['book_num'])) $bNum = str_pad($book['book_num'], 3, '0', STR_PAD_LEFT);
        
        $bDir = $narrativeDir . '/b' . $bNum;
        
        // If the corresponding book directory exists, create its local manifest
        if (is_dir($bDir)) {
            $metaPath = $bDir . '/ovab.json';
            // Only write if `ovab.json` doesn't already exist to prevent overwriting newer metadata
            if (!file_exists($metaPath)) {
                file_put_contents($metaPath, json_encode(['title' => $book['book_title']], JSON_PRETTY_PRINT));
            }
        }
        
        // Proceed to chapter migration if chapter data exists
        if (!isset($book['chapters'])) continue;
        
        foreach ($book['chapters'] as $cIndex => $chap) {
            // Skip chapters missing a title
            if (!isset($chap['chap_title'])) continue;
            
            // Determine the chapter number: use index (+1) or explicit 'chap_num'
            // Pad to 3 digits (e.g., '001')
            $cNum = str_pad($cIndex + 1, 3, '0', STR_PAD_LEFT);
            if (isset($chap['chap_num'])) $cNum = str_pad($chap['chap_num'], 3, '0', STR_PAD_LEFT);
            
            $cDir = $bDir . '/c' . $cNum;
            
            // If the corresponding chapter directory exists, create its local manifest
            if (is_dir($cDir)) {
                $metaPath = $cDir . '/ovab.json';
                // Only write if `ovab.json` doesn't already exist
                if (!file_exists($metaPath)) {
                    file_put_contents($metaPath, json_encode(['title' => $chap['chap_title']], JSON_PRETTY_PRINT));
                }
            }
        }
    }
    // Output progress status for the processed narrative
    echo "Migrated katie.json titles to ovab.json for " . basename($narrativeDir) . "\n";
}
?>
