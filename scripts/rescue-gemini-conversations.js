/**
 * ============================================================================
 * ARCHITECTURE & MODULE OVERVIEW: rescue-gemini-conversations.js
 * ============================================================================
 * Purpose:
 * A client-side bookmarklet/script designed to be executed directly in the 
 * browser console on the Google Gemini web interface. It extracts the current 
 * chat history (user prompts and model responses) and exports it as a formatted 
 * Markdown file.
 * 
 * Architectural Role:
 * Data retrieval and backup utility. Since Gemini's DOM may change over time, 
 * this script relies on specific CSS class selectors to identify message nodes.
 * It serves as an emergency export tool to preserve conversational lore.
 * 
 * Key Components:
 * 1. DOM Traversal: Targets `.query-text-line` and `.response-container-content`.
 * 2. Markdown Transformation: Maps DOM nodes to a structured string formatted 
 *    with Markdown speaker labels.
 * 3. Blob Export: Uses HTML5 Blob API to generate a client-side download, 
 *    bypassing the need for a server or encountering clipboard size limits.
 * 
 * Maintenance Notes:
 * - Extremely brittle to UI updates. If Google updates the CSS classes for 
 *   queries or responses, `messageNodes` will return empty and the selectors
 *   will need to be inspected and updated.
 * - Requires the user to scroll to the top of the chat beforehand to ensure 
 *   lazy-loaded elements are present in the DOM.
 * ============================================================================
 */

(function extractChat() {
    // 1. Target both user queries and model responses. 
    // querySelectorAll automatically returns them in document order, 
    // which naturally preserves the conversational timeline.
    const messageNodes = document.querySelectorAll('.query-text-line, .response-container-content');
    
    // Safety check to ensure selectors are still valid and content is loaded.
    if (messageNodes.length === 0) {
        console.error("No nodes found. Make sure you are scrolled to the top so everything is rendered.");
        return;
    }

    // 2. Extract and format the text
    const chatLog = Array.from(messageNodes).map((node) => {
        let speaker = "";
        
        // Check which class the node has to apply the correct Markdown label
        // Identifies user prompts
        if (node.classList.contains('query-text-line')) {
            speaker = "**User:**\n";
        } 
        // Identifies AI responses
        else if (node.classList.contains('response-container-content')) {
            speaker = "**Model:**\n";
        }
        
        // Extract raw text. InnerText is used over innerHTML to naturally 
        // strip HTML tags while maintaining basic line breaks.
        return speaker + node.innerText.trim();
    }).join('\n\n---\n\n'); // Separate messages with Markdown horizontal rules

    // 3. Package the string into a Blob to bypass clipboard limits
    // This allows downloading massive conversations without browser memory crashes.
    const blob = new Blob([chatLog], { type: 'text/markdown' });
    const url = URL.createObjectURL(blob);
    
    // 4. Force a silent download
    // Creates a temporary anchor tag, triggers a click, and initiates download.
    const a = document.createElement('a');
    a.href = url;
    a.download = 'meridian-city-lore-backup.md'; // Default export filename
    document.body.appendChild(a);
    a.click();
    
    // 5. Cleanup
    // Remove the temporary element and free up the object URL memory
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    
    console.log("Extraction complete. Markdown file downloaded.");
})();