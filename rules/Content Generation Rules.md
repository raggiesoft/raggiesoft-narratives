# Narrative Restructuring & Formatting Rules

## 1. Heading Hierarchy & Naming Conventions

 Books: Must be formatted as Heading 1 (⁠# Book [Number]: [Title]⁠).

 * **Chapters:** Must be formatted as Heading 2 and always date-stamped (⁠## Chapter [Number]: [Title] – [Day of Week], [Month] [Date], [Year]⁠).

 * **Parts:** Must be formatted as Heading 3 and always time-stamped (⁠### Part [Number]: [Title] – [Time] [AM/PM]⁠).

 * **Sidebars / POV Shifts:** Any cutaways to different locations or character POVs outside the main group (e.g., antagonists, side characters) should be labeled as an "Interlude" instead of a "Part" to signify the shift, while maintaining the exact same timestamp rule (⁠### Interlude: [Title] – [Time] [AM/PM]⁠).

## 2. Time & Pacing Constraints

 * **The 24-Hour Rule:** A single chapter can never span more than one calendar day. We can break a day up into multiple chapters if we're moving to a new section while still on the same day, if a Part doesn't seem like enough of a break.

* **The Midnight Threshold:** If a scene crosses midnight (e.g., late-night talks, nightmares, parties), a brand new chapter must be started immediately to reflect the new date.

* **AI Date/Time Generation:** If explicit dates or times are missing from the original text, the AI has full permission to deduce, calculate, or invent plausible dates and timestamps based on narrative clues (e.g., calculating the start of the semester based on a known Homecoming date).

## 3. Narrative Perspective & Grounding

 * **No "Winking" at the Reader:** The narrative must stay strictly grounded in the immediate reality and perspective of the characters in the scene. Avoid breaking the fourth wall, heavy-handed foreshadowing, or explicitly explaining future plot points (e.g., telling the reader about "the dorm's worst-kept secret" before the characters organically experience the rumors). Show, don't tell.

## 4. AI Safety & Content Adaptation

 * **Graceful Adaptation for Sensitive Content:** If a sequence contains explicit intimacy, detailed anatomical explanations, or intense medical/sexual trauma topics that will trigger AI safety filters (e.g., "An Unconventional Study Session"), the AI is instructed to rewrite the scene as a tasteful, high-level narrative summary. The rewrite must focus entirely on the emotional weight, character development, and plot progression rather than granular, clinical, or explicit mechanics.

* **No Copyrighted or Trademarked Material:** if, in the course of content generation, we cone across a copyrighted or trademarked word, we must substitute it for an in-universe brand. If a suitable substitute is not on the attached list, we should come up with a plausible in-universe brand OR simply use a generic word as a copyright safe replacement. The goal is to produce content that does not earn a Cease and Desist letter.
* **No External Email Addresses in Any Narrative:** NEVER include actual email addresses (`user@domain.tld`) ANYWHERE in any narrative or lore file unless the domain is one of Michael P. Ragsdale's actual owned websites (such as `raggiesoft.com` or `engineroom-records.com`). In particular, NEVER use `@cpi.edu` (which belongs to a real-world institution, Central Pennsylvania Institute) or any other `.edu` / third-party domain. When depicting in-universe emails, format the `To:`, `From:`, and `CC:` headers using character names and roles only (with `<br>` at the end of each header line inside a Markdown blockquote so each header renders on its own line).

## 5. CPI Campus Door & Poly Pass Accessibility Mechanics (`aubrie`)

* **Hallway / Exterior Entry (Going into a Locked Room or Building):**
  * For **ALL** locked doors on campus that a student's **Poly Pass** is authorized to unlock, tapping the Poly Pass to the electronic reader unlocks the latch for someone to push or pull the door open.
  * For the **handicap suites in all residence halls across campus** (not just Bennett Hall, including both **Room 214** and **Room 216** in Bennett Hall) as well as any other locked door on campus, a student registered with the **Office of Educational Accessibility (OEA)** (such as Jordan Fuller) has their Poly Pass encoded to **also activate the motorized automatic door opener of ANY locked door** they are authorized to access.
* **Inside the Room (Exiting to the Hallway in Handicap Suites like Rooms 214 & 216):**
  * To exit from inside the room to the hallway (or to open the door from the inside when someone knocks), press the square brushed stainless-steel handicap actuator button mounted on the interior wall beside the doorframe (stamped with the blue wheelchair symbol and **`PUSH TO OPEN`** in bold blue block letters). Pressing this button both unlocks the electronic latch and activates the automatic door opener.
* **Hold-Open Dwell Timer & Auto-Relock (5 Seconds Standard / 10 Seconds Dorms):**
  * Whenever an accessible door is activated (either via an OEA-encoded Poly Pass from the outside or the **`PUSH TO OPEN`** handicap button from the inside), it is programmed to stay open for **5 seconds** on normal campus doors, and **10 seconds** for the dorms (including dorm exterior doors and handicap suite doors like Rooms 214 and 216), and then **automatically close and lock**.
* **Jordan's Undiagnosed Autism in Early Fall 2014 & Safe People List:**
  * Because Aubrie Sinclair did not believe in doctors or vaccines and never took Jordan to a physician or psychologist in his entire life, at the start of Fall 2014 (`e001`), **Jordan does not yet know that he is autistic** (so his internal POV should never use clinical self-labels like "autistic meltdown" prior to his formal discovery/diagnosis).
  * The **Fire Drill** (`c011/s003.md`) is the pivotal moment that cements Jordan's trust in his RA, **Isabel Dawson**, to the point where he officially adds her to his inner list of **Safe People**. Even after the all-clear when heading back into Room 214 (`c011/s003.md`–`s004.md`), Jordan remains visibly shaken up with dried tears, lingering fresh tears, and the quiet hitching sniffles of crying that is trying to subside.

## 6. Zoë Wren Brooks's Autistic Cognitive Logging System (`ZOË.Systems.Logs` — Full Spec in `raggiesoft-narratives/lore/aubrie/zoe-logging-system.md`)

* **Neurodivergent Architecture (Diagnosed Since Infancy):** Zoë Wren Brooks has been formally diagnosed as autistic since she was an infant and has **always** processed her internal thoughts, sensory/emotional telemetry, and logistics like a C#/.NET application writing to a structured runtime log. While Jordan's crashes come from conflicting rules (`System.IO.FileNotFoundException()` against `AubrieSinclair.LegacyScript.dll`) and Aubrie Kelly's come from sensory volume (`100`), Zoë's processor spikes when a system is **inefficient, illogical, unscripted, or chaotic** (which is why coding **`Fin#`** and **`Z-SOX-302`** calms her down).
* **Timeline & Disclosure Constraint:** At Freshman Fall Break (`October 2014`, `e001/c016`–`c020`), Zoë **simply has not disclosed her lifelong autism diagnosis to her friends yet**; do **not** insert `ZOË.Systems.Logs.*` blocks prior to her autism disclosure arc (and when porting her disclosure scene from `aubrie-old.docx`, ensure she is disclosing her lifelong infant diagnosis rather than wondering for the first time if she is autistic).
* **The 5 Canonical Log Streams:** Every log line must start with `ZOË.Systems.Logs.<StreamName>:` using one of the five streams:
  1. `ZOË.Systems.Logs.KernelLog:` (core cognitive processor, `Hypothesis:`, `Analysis:`, `Conclusion:`, `.dll`/`.dat` script lookups, `System.IO.FileNotFoundException()`, `WARNING:`, `CRITICAL ERROR:`, `CRITICAL ALERT:`)
  2. `ZOË.Systems.Logs.EmotionalStateLog:` (affective telemetry, heart/skin responses, `'High_Gratification'`, `'Love(Jordan)' == True`, `Re-classifying 'Self' from 'Pact.Member' to 'Jordan.SafePerson.Absolute'`)
  3. `ZOË.Systems.Logs.SystemSchedulerLog:` (executive function, thread priority recalculations, transit routing, and node tracking: `Node(CPI.Campus)`, `Node(Geneva.Street)`, `Node(Star City.Roanoke)`)
  4. `ZOË.Systems.Logs.ActionLog:` (physical/motor execution and grounding interventions: `Execute: ContinueCuddle(Jordan)`, `Executing 'Protocol: Comfort.Hug'`, `Executing 'Protocol: Emergency_Grounding'`)
  5. `ZOË.Systems.Logs.InternalStateLog:` (long-term somatic/biological self-monitoring)
* **Fixed Concurrency Thread IDs:**
  * `Thread 1 (Self)` — Zoë Wren Brooks (`Agent(Self)`)
  * `Thread 2 (Observers)` (or `Thread 2 (Aubrie)`, `Thread 2 (Madison)`) — Observing Pact members
  * `Thread 3 (Jordan)` — `Jordan.OS` / `Agent(Jordan)` / `agentJordan`
  * `Thread 4 (Mark)` — Mark Anderson (`Agent(Mark)`)
  * `Thread 5 (Hannah)` — Hannah Miller (`Agent(Hannah)`)
* **Parser & Syntax Highlighting Support (`stardust-parsedown.php` & `oliver.php`):** Consecutive `ZOË.Systems.Logs.*` paragraphs (or blocks wrapped in `<zoe-log>...</zoe-log>` / `<kernellog>...</kernellog>` / ```` ```zoe-log ````) are automatically grouped and rendered with full WCAG 2.1 AAA Light/Sepia/Dark C#/.NET syntax highlighting and right-aligned `MM-DD-YYYY HH:MM:SS` timestamp pills.