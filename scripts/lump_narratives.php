<?php
echo "Starting AI Context Lumper...\n";
echo "Validating directories...\n";

// Define our working directories based on the script's location
$baseDir = dirname(__DIR__); // Moves up to /raggiesoft-narratives
$booksBaseDir = $baseDir . '/books';
$lumpBaseDir = $baseDir . '/books-ai-lump';

if (!is_dir($booksBaseDir)) {
    die("Error: Base books directory not found at {$booksBaseDir}\n");
}

// Ensure the target base directory exists. If it does, WIPE IT CLEAN FIRST.
if (is_dir($lumpBaseDir)) {
    echo "Cleaning existing lump directory: /books-ai-lump/...\n";
    // Function to recursively delete a directory and its contents
    function deleteDir($dirPath) {
        if (!is_dir($dirPath)) {
            return;
        }
        $files = array_diff(scandir($dirPath), array('.', '..'));
        foreach ($files as $file) {
            $path = $dirPath . '/' . $file;
            is_dir($path) ? deleteDir($path) : unlink($path);
        }
        rmdir($dirPath);
    }
    deleteDir($lumpBaseDir);
}

// Recreate the fresh, empty base directory
echo "Creating fresh master output directory: /books-ai-lump/\n";
mkdir($lumpBaseDir, 0755, true);

// Helper function to create clean filenames and folder names
function slugify($string) {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string), '-'));
    return preg_replace('/-+/', '-', $slug);
}

// Scan for all narrative series folders inside the /books directory
$narrativeDirs = array_filter(glob($booksBaseDir . '/*'), 'is_dir');

if (empty($narrativeDirs)) {
    die("No narrative folders found in {$booksBaseDir}\n");
}

// Loop through each series (e.g., /books/rachel)
foreach ($narrativeDirs as $narrativeDir) {
    $narrativeName = basename($narrativeDir); // e.g., 'rachel'
    $manifestFile = $narrativeDir . '/katie.json';
    
    echo "\n========================================================\n";
    echo "Scanning Narrative Series: {$narrativeName}\n";
    echo "========================================================\n";

    if (!file_exists($manifestFile)) {
        echo "[Skipping] No katie.json found in /books/{$narrativeName}/\n";
        continue;
    }

    echo "Loading manifest from: /books/{$narrativeName}/katie.json...\n";
    $katie = json_decode(file_get_contents($manifestFile), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "[Error] Invalid JSON syntax in /books/{$narrativeName}/katie.json\n";
        continue;
    }

    $books = isset($katie['books']) ? $katie['books'] : $katie;
    $seriesTitle = isset($katie['series_title']) ? $katie['series_title'] : $narrativeName;
    
    $bookCount = count($books);
    echo "Successfully parsed {$bookCount} books in '{$seriesTitle}'.\n\n";

    // Create the specific narrative folder inside books-ai-lump
    $seriesLumpDir = $lumpBaseDir . '/' . $narrativeName;
    if (!is_dir($seriesLumpDir)) {
        echo "  [Setup] Creating directory: /books-ai-lump/{$narrativeName}/\n";
        mkdir($seriesLumpDir, 0755, true);
    }

    foreach ($books as $book) {
        echo "  Processing Book: {$book['book_title']}...\n";
        
        // Format the directory and filename (e.g., 001-book-1-the-delaney-street-years.md)
        $bookSlug = slugify($book['book_title']);
        $paddedNum = str_pad($book['book_num'], 3, '0', STR_PAD_LEFT);
        $fileName = "{$paddedNum}-{$bookSlug}.md";
        
        $outputPath = $seriesLumpDir . '/' . $fileName;
        
        // Start building the master file content
        $masterContent = "---\n";
        $masterContent .= "title: \"{$book['book_title']}\"\n";
        $masterContent .= "series: \"{$seriesTitle}\"\n";
        $masterContent .= "---\n\n";
        
        // Heading 1: The Book Name
        $masterContent .= "# {$book['book_title']}\n\n";
        
        foreach ($book['chapters'] as $chapter) {
            $cleanChapTitle = strip_tags($chapter['chap_title']);
            echo "    [Chapter] Reading: {$cleanChapTitle}\n";
            
            // Heading 2: The Chapter Name 
            $masterContent .= "## " . $cleanChapTitle . "\n\n";
            
            foreach ($chapter['parts'] as $part) {
                // Point to the specific narrative directory
                $filePath = $narrativeDir . '/' . $part['file_path'];
                
                if (!file_exists($filePath)) {
                    echo "      -> [Warning] Missing file: {$part['file_path']}\n";
                    continue;
                }
                
                echo "      -> [Part] Appending: {$part['file_path']}\n";
                
                // Heading 3: The Part Name 
                $masterContent .= "### " . strip_tags($part['part_title']) . "\n\n";
                
                // Ingest the individual part's markdown
                $partContent = file_get_contents($filePath);
                
                // Regex to strip the individual YAML Frontmatter block
                $partContent = preg_replace('/^---[\s\S]*?---\s*/', '', ltrim($partContent));
                
                // Append the cleaned narrative text
                $masterContent .= trim($partContent) . "\n\n";
            }
        }
        
        // Write the compiled string to the new file, overwriting if it exists
        echo "    [Write] Saving compiled file to: {$outputPath}\n";
        file_put_contents($outputPath, $masterContent);
        echo "    [Success] Finished lumping {$book['book_title']}\n\n";
    }
}

echo "========================================================\n";
echo "Lumping Complete! All series are packaged and ready.\n";
echo "========================================================\n";
?>