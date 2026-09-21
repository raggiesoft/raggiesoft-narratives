**# Stardust Engine Library: Migration Proposal

This document outlines the architectural plan and migration strategy to transition the RaggieSoft platform away from paid dependencies (Web Awesome Pro, Font Awesome Pro, Bootstrap) to a fast, lightweight, and native framework called the **Stardust Engine Library**. 

This plan incorporates all the critical lessons learned from our initial test run to ensure a flawless deployment when your subscriptions eventually expire.

## 1. Core Architecture

The Stardust Engine Library is built on three pillars:

*   **Native HTML5 Elements:** Replacing proprietary custom elements (like `<wa-dialog>` and `<wa-tree>`) with native, accessible HTML5 equivalents (like `<dialog>` and `<details>`).
*   **Vanilla JS Interactivity:** A single, lightweight `raggiesoft-ui.js` file handles interactions by simply toggling CSS classes and ARIA attributes, rather than relying on shadow DOMs or heavy frameworks.
*   **Phosphor Icons:** A clean, open-source replacement for Font Awesome Pro that offers a similar weight and style variety without the recurring cost.

### Component Mapping

| Legacy Web Awesome | Stardust Engine Native | Notes |
| :--- | :--- | :--- |
| `<wa-button>` | `<button class="rs-btn">` | Styled via CSS variables. |
| `<wa-badge>` | `<span class="rs-badge">` | |
| `<wa-spinner>` | `<div class="rs-spinner">` | Standard CSS border animation. |
| `<wa-dialog>` | `<dialog class="rs-modal">` | Native HTML5 element. Opened via `showModal()` and closed via `.close()`. |
| `<wa-tree>` | `<details class="rs-tree">` | Native HTML5 accordion behavior. |
| `<wa-icon>` | `<i class="ph ph-icon">` | Phosphor Icons webfont implementation. |
| Hamburger Menu | `<button class="rs-hamburger">` | CSS-animated spans toggled via `aria-expanded`. |

## 2. Theming Engine

The legacy architecture relied heavily on a fragmented 5-file system (`root.css`, `header.css`, `footer.css`, `extras.css`, `safety-net.css`) and Bootstrap's `data-bs-theme` attribute. The new system is vastly simplified.

### Variable Unification
All existing `--wa-color-*` and `--bs-*` variables will be standardized to a clean `--rs-*` prefix (e.g., `--rs-primary`, `--rs-bg`, `--rs-text`).

### CSS Modules
The fragmented CSS files for each brand (Knox, Aethel, etc.) will be compiled into single-file modules (e.g., `theme-knox.css`). 

### Scope and Injection
Instead of injecting CSS globally in `<head>`, the active theme's styles will be scoped to a class on the `<body>` tag:
```html
<body class="theme-knox">
```
Dark mode is handled natively via `@media (prefers-color-scheme: dark)` inside the CSS module, or forced via a `.force-dark` utility class, completely eliminating the need for `data-bs-theme`.

## 3. Critical Migration Steps & Lessons Learned

When executing this migration in the future, adhere to this sequence to prevent the bugs we encountered during our test run:

### Phase 1: CSS Compilation & Variable Mapping
*   Concatenate the legacy 5-file arrays into single `theme-[name].css` files.
*   **CRITICAL LESSON:** When mapping variables to the new `--rs-*` system, explicitly audit the dark mode background and text colors. Our test run resulted in "white-on-white" text because the legacy Bootstrap/Web Awesome variables mapped unpredictably. Ensure `--rs-bg` and `--rs-text` are strictly defined for both light and dark contexts.

### Phase 2: HTML Component Replacement
*   Execute global Find & Replace for all Web Awesome tags (`<wa-*>` -> `<... class="rs-*">`).
*   **CRITICAL LESSON:** Do not forget to audit JavaScript files! Files like `stardust-player.js` dynamically injected `<wa-spinner>` elements into the DOM via string literals. These will break if not updated to the new HTML structure.

### Phase 3: Elara SPA Router Updates
*   **CRITICAL LESSON:** `elara-spa.js` currently syncs `<head>` attributes and `<html>` attributes during soft navigation. Because the new theming engine relies on `<body>` scoping, `elara-spa.js` **must** be updated to sync `document.body.className`. If this is missed, navigating between brands (e.g., Knox to RaggieSoft Corporate) will fail to apply the correct color theme.

### Phase 4: UI Interaction Wiring
*   Implement `raggiesoft-ui.js` to handle global interactions like the off-canvas `.rs-sidebar`.
*   Ensure redundant inline scripts (like legacy Bootstrap dropdown toggles in `header.php`) are purged to prevent event listener collisions.
*   Update `konami.php` to use the native `<dialog>` `.close()` method instead of Web Awesome's `.hide()` method.

## Conclusion
By treating the UI as a native extension of the browser rather than a proprietary web component library, the Stardust Engine Library will drastically reduce load times, eliminate subscription costs, and simplify long-term maintenance.
**