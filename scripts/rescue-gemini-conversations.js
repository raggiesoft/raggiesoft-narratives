/**
 * ============================================================================
 * READ-ALONG TEACHING OVERVIEW: rescue-gemini-conversations.js
 * ============================================================================
 * WHAT THIS FILE IS:
 *   A standalone client-side browser DevTools Console snippet / bookmarklet
 *   that extracts a long Google Gemini web chat transcript directly from the
 *   rendered page DOM and downloads it to your computer as a clean `.md`
 *   (Markdown) archive file.
 *
 * WHERE / HOW IT RUNS:
 *   Stored in `raggiesoft-narratives/scripts/rescue-gemini-conversations.js` as
 *   an author utility. Unlike Node.js server scripts, you paste and run this
 *   inside Chrome/Firefox/Safari Developer Tools Console on an open Gemini tab
 *   after scrolling to the top of the conversation so all messages are loaded.
 *   (Still syntax-validated with `node --check` as pure standard JavaScript!)
 *
 * WHAT INPUTS IT CONSUMES:
 *   - Live DOM elements matching `.query-text-line` (the human author's prompts)
 *     and `.response-container-content` (the AI model's responses).
 *
 * WHAT OUTPUT IT PRODUCES:
 *   - Automatically downloads a local Markdown file named
 *     `meridian-city-lore-backup.md` with alternating `**User:**` and `**Model:**`
 *     sections separated by `---` horizontal rules.
 *
 * 100-LEVEL JAVASCRIPT CONCEPTS TAUGHT IN THIS FILE:
 *   1. Named IIFE (`(function extractChat() { ... })()`): Runs immediately when
 *      pasted into the Console without leaving global variables behind (so you
 *      can paste and run it a second time in the same tab without `const` errors!).
 *   2. Document-Order Multi-Selector (`querySelectorAll('A, B')`): How CSS comma
 *      selectors interleave two classes in exact top-to-bottom reading order.
 *   3. `Array.from(nodeList).map(...).join(...)`: Converting a DOM `NodeList`
 *      into a formatted string pipeline.
 *   4. Client-Side File Generation (`Blob` + `URL.createObjectURL` + `<a download>`):
 *      Creating a downloadable file 100% in browser RAM with zero backend server,
 *      bypassing OS clipboard size limits on multi-megabyte transcripts.
 * ============================================================================
 */

(function extractChat() {
    /**
     * STEP 1: QUERY ALL CONVERSATION TURNS IN CHRONOLOGICAL DOCUMENT ORDER
     * Passing comma-separated selectors (`.query-text-line, .response-container-content`)
     * to `document.querySelectorAll()` returns a single `NodeList` sorted strictly
     * in top-to-bottom HTML order—NOT all queries first and all responses second!
     * That preserves the natural back-and-forth timeline of the conversation.
     */
    const messageNodes = document.querySelectorAll('.query-text-line, .response-container-content');
    
    /* Guard check: if 0 elements matched, either the chat hasn't rendered or
     * the web app's CSS class names changed. Log a helpful hint and stop. */
    if (messageNodes.length === 0) {
        console.error("No nodes found. Make sure you are scrolled to the top so everything is rendered.");
        return;
    }

    /**
     * STEP 2: TRANSFORM DOM NODES INTO MARKDOWN BLOCKS
     * `querySelectorAll` returns a `NodeList` (which doesn't have `.map()`).
     * `Array.from(messageNodes)` converts it into a true JS `Array` so we can:
     *   1. `.map((node) => ...)` each DOM element into a labeled Markdown string.
     *   2. `.join('\n\n---\n\n')` all turns together with horizontal rule dividers.
     */
    const chatLog = Array.from(messageNodes).map((node) => {
        let speaker = "";
        
        /* Inspect `node.classList` to label who spoke in this turn. */
        if (node.classList.contains('query-text-line')) {
            speaker = "**User:**\n";
        } 
        else if (node.classList.contains('response-container-content')) {
            speaker = "**Model:**\n";
        }
        
        /* Why `node.innerText` instead of `node.innerHTML` or `node.textContent`?
         * - `innerHTML` would dump messy `<div class="..."><span>` tags into our Markdown.
         * - `textContent` ignores visual paragraph breaks (`<p>`, `<br>`) and mashes lines together.
         * - `innerText` is layout-aware: it strips HTML tags while preserving visible line breaks! */
        return speaker + node.innerText.trim();
    }).join('\n\n---\n\n');

    /**
     * STEP 3: PACKAGE THE MARKDOWN STRING AS AN IN-MEMORY `Blob`
     * `navigator.clipboard.writeText()` often truncates or fails on giant 500KB+
     * transcripts. A `Blob` (Binary Large Object) wraps our string in browser
     * memory as a virtual `text/markdown` file, and `URL.createObjectURL(blob)`
     * mints a temporary `blob:https://...` pointer URL to those bytes.
     */
    const blob = new Blob([chatLog], { type: 'text/markdown' });
    const url = URL.createObjectURL(blob);
    
    /**
     * STEP 4: TRIGGER A SYNTHETIC `<a download>` CLICK
     * Browsers download `blob:` URLs cleanly when clicked through an `<a download="filename.md">`
     * element attached to `document.body`.
     */
    const a = document.createElement('a');
    a.href = url;
    a.download = 'meridian-city-lore-backup.md'; // Default saved filename in Downloads
    document.body.appendChild(a);
    a.click();
    
    /**
     * STEP 5: CLEAN UP TEMPORARY DOM & MEMORY
     * Remove the invisible `<a>` tag and call `URL.revokeObjectURL(url)` so the
     * browser can garbage-collect the in-memory `Blob` buffer immediately.
     */
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    
    console.log("Extraction complete. Markdown file downloaded.");
})();
