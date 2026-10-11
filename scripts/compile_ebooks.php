<?php
/**
 * compile_ebooks.php
 * Converts lumped Novel Markdown files into EPUB and PDF formats using Pandoc.
 */

$workspaceRoot = dirname(__DIR__, 2);
$lumpDir = $workspaceRoot . '/raggiesoft-narratives/books-ai-lump';
$outBaseDir = $workspaceRoot . '/raggiesoft-assets/raggiesoft-books/books';

if (!is_dir($lumpDir)) {
    die("Lump directory not found.\n");
}

$dirs = array_filter(glob($lumpDir . '/*'), 'is_dir');

foreach ($dirs as $novelDir) {
    $slug = basename($novelDir);
    $mdFile = $novelDir . '/' . $slug . '-complete.md';
    
    if (!file_exists($mdFile)) {
        continue;
    }
    
    echo "Processing $slug...\n";
    $content = file_get_contents($mdFile);
    
    // 1. Handle Images:
    // Replace {{CDN}} with absolute local path so Pandoc can embed them.
    $localCdnPath = $workspaceRoot . '/raggiesoft-assets';
    $content = str_replace('{{CDN}}', $localCdnPath, $content);
    // Strip trailing URL hashes like #fullwidth which break Pandoc's local file locator
    $content = preg_replace('/(\.(?:jpg|jpeg|png|gif|webp))#[^)]+/', '$1', $content);
    
    // 2. Handle Custom Tags
    // <aac> tags: Convert to italics
    $content = preg_replace('/<aac>(.*?)<\/aac>/is', '*$1*', $content);
    // <asl>, <sgn>, <sms>: Convert to italics
    $content = preg_replace('/<(asl|sgn|sms)>(.*?)<\/\1>/is', '*$2*', $content);
    
    // <lang> tags: Convert to italics with inline translation
    $content = preg_replace_callback('/<lang(?:[^>]*?)trans="([^"]+)"(?:[^>]*?)>(.*?)<\/lang>/is', function($matches) {
        $translation = $matches[1];
        $original = $matches[2];
        return "*{$original}* ({$translation})";
    }, $content);
    // Fallback if no translation attribute exists
    $content = preg_replace('/<lang[^>]*>(.*?)<\/lang>/is', '*$1*', $content);
    
    // 3. Save preprocessed Markdown to a temporary file
    $tmpMd = $novelDir . '/' . $slug . '-pandoc.md';
    file_put_contents($tmpMd, $content);
    
    // 4. Ensure output directory exists
    $outDir = $outBaseDir . '/' . $slug;
    if (!is_dir($outDir)) {
        mkdir($outDir, 0755, true);
    }
    
    // 5. Run Pandoc
    $epubFile = $outDir . '/' . $slug . '.epub';
    $pdfFile = $outDir . '/' . $slug . '.pdf';
    
    echo "  Generating EPUB...\n";
    $epubCmd = sprintf(
        'pandoc %s -o %s --toc --toc-depth=3',
        escapeshellarg($tmpMd),
        escapeshellarg($epubFile)
    );
    exec($epubCmd, $output, $returnVar);
    if ($returnVar !== 0) {
        echo "  [Error] EPUB generation failed for $slug\n";
    }
    
    // PDF generation (requires a PDF engine like pdflatex, xelatex, or wkhtmltopdf)
    // Note: If no PDF engine is installed, this will fail. We'll add a check.
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
        echo "  [Warning] PDF generation failed for $slug. Ensure wkhtmltopdf or pdflatex is installed.\n";
    }
    
    // Cleanup
    unlink($tmpMd);
    echo "  Done.\n";
}
echo "All E-Books compiled!\n";
