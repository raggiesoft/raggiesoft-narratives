# Parked Story Crossroads Archive & Future "What If?" Notes (`lore/parked-crossroads/`)

**Archived Date:** November 18, 2026  
**Architectural Status:** **Parked in Favor of Standalone `Backstage` "What If?" Dossiers (`backstage.raggiesoft.com`)**

---

## 1. Why Story Crossroads Are Parked (While Leaving Engine Code Intact)

Even with automated `paige.php` `forbidden_terms` anti-bleed checks and per-branch `BRANCH-CANON.md` ledgers, maintaining full multi-decade branching story trees across interconnected novels (`books/katie`, `books/luna-leo`, `books/jack-sarah`, `books/rachel`) creates heavy cognitive and editorial overhead whenever downstream events ripple across books.

Therefore:
1. **All novels in `/raggiesoft-narratives/books/` are restored to 100% linear mainline storylines.**
2. **All Crossroads engine code remains intact and dormant** across `paige.php`, `lump_narratives.php`, `books.raggiesoft.com` (`oliver.php`, `toc.php`, `isabel.php`, `reader.css`, `stardust-spa.js`), Apple Multiplatform SwiftUI (`BookReader`), and Windows `.NET 10` WPF (`BookReader`). Because the engine only activates when `books/<slug>/crossroads.json` exists, keeping `crossroads.json` parked here leaves every novel 100% linear while preserving the engineering work in case we ever revisit interactive branching in the future.
3. **Alternate divergences live as self-contained `RaggieSoft Backstage` `"What If?"` segments** (`raggiesoft-narratives/backstage/books/<slug>/what-if-*.md`), linked directly from the mainline chapters where each divergence occurs via YAML `backstage:`.

---

## 2. Historical Precedent: Why `/books/alex-chloe` Reads Similarly to `/books/luna-leo`

In Ocean View Archives history, turning a *"What If?"* into a standalone story has deep roots:
- ***Beyond Words* (`/books/alex-chloe`) originally began as a *"What If?"* spin-off of *Luna & Leo* (`/books/luna-leo`).**
- In the earliest draft of `/books/luna-leo`, **Olivia** never came back into **Leo's** life, and `/books/alex-chloe` was created to explore *"What if Olivia returned?"*
- Over time, both projects grew into full, distinct, standalone narratives of their own (with Olivia later returning in mainline `/books/luna-leo` as well).

---

## 3. Archived Crossroads Manuscripts in This Folder (`lore/parked-crossroads/`)

All authored multi-part Crossroads manifests (`crossroads.json`) and branch manuscripts (`paths/`) are preserved verbatim in subdirectories alongside their `BRANCH-CANON.md` files:

### A. *Katie & Nathaniel* (`lore/parked-crossroads/katie/`)
- **`paths/brittany-respects-no-company/` (`new-years-rule-2002`, Dec 31, 2002 — 6 Parts)**:
  - **Premise:** Staying on Arvin Road while Uncle David checks on Grandma Ruth, Aunt Liz, and Chelsea in Newport, Rhode Island (with Nate having promised Heather at the bus loop on December 20 that even if Heather and Wesley hadn't gone to Minnesota for Winter Break, nobody would be over under Dad's strict no-company rule), seventeen-year-old **Brittany Fletcher** hangs up on Tyler's Smoker's Corner party call, checks the brass deadbolt herself, rings in 2003 under the navy fleece blanket with Katie and Nate over ginger ale and pepperoni pizza, graduates in the fourth purple gown at the Virginia Beach Pavilion in June 2003, joins the Summer 2003 ten-campus college tour for rising-junior **Wesley**, and learns Uncle David's **`1991 Crestline Garrison 350` Crew Cab 4x4** (`L-1-2-3-R` cast-iron four-speed + `Hartley` / `Factory Service Manual` / `Maintenance Binder`) for her modest-income `StreamTube` and `The Grid` channel while Uncle David is alive and healthy.
  - **Published Standalone Backstage Dossier:** [`backstage/books/katie/what-if-brittany-respected-no-company-new-years-2002.md`](../../backstage/books/katie/what-if-brittany-respected-no-company-new-years-2002.md)
- **`paths/accept-heather-virginia/` (`pavilion-apology-2003`, Jun 14, 2003 — 8 Parts)**:
  - **Premise:** Outside the Virginia Beach Pavilion after Class of 2003 Commencement, Katie and Nate accept **Heather Collins's** full-accountability apology as sixteen-year-old **Wesley** cheers. Staying in Tidewater under `Code of Virginia § 23-7.4` in-state tuition domicile (`"Wherever Nate goes, Katie goes too — and wherever Heather goes, Wesley goes with her"`), they take rising-junior Wesley on a ten-campus Summer 2003 tour (`CCHR`, `VDU`, `Warwick University` beside `<led>112 NNTC</led>`, `College of Charles & Anne`, `CVU`, `George Wythe University`, `Shenandoah Valley University` [`SVU`, Harrisonburg] Open House cut short, `Roosevelt Rehabilitation Center` [`RRC`, Fishersville] stopping short outside attached **`Redmond Hall`** when Wesley cries in his Safe Person Heather's arms over gender-separated dorms, `CPI` Blacksburg hills vs. battery life, and an August 2003 Minnesota family visit + `NSSU` tour), eliminate `Tiffany Sloan`, stop `Brandon Mercer` cold in May 2004, celebrate Nate & Heather's 2007 marriage and Heather's 5-step Special Education career ladder culminating at `Prairie Twin Interactive, LLC`, film Brittany's `1991 Crestline Garrison 350` `L-1-2-3-R` videos with living Uncle David, and still achieve Katie's U.S. Senate career plus both **Brittany** and **Chelsea** rescues.
  - **Permanent Wesley-Respect Guardrail:** Across ALL *Katie & Nathaniel* concepts, Nate and Katie NEVER coldly reject Heather and roll away from Wesley.
  - **Published Standalone Backstage Dossier:** [`backstage/books/katie/what-if-virginia-domicile-unbroken-trust-and-both-rescues.md`](../../backstage/books/katie/what-if-virginia-domicile-unbroken-trust-and-both-rescues.md)

### B. *Luna & Leo* & *Jack & Sarah* (`lore/parked-crossroads/luna-leo/` & `lore/parked-crossroads/jack-sarah/`)
- **`luna-leo/paths/luna-throws-delores-out/` (`sibling-weekend-2003`, Oct 11, 2003 — 3 Parts)**:
  - **Premise:** At `5:10 PM` on Saturday of the Columbus Day Sibling Weekend at **Stella Porter's** Virginia Beach house, right as **Delores Mahone** clamps both hands around **Leo's** forearms to yank him onto her lap after shrieking at the `MagnaByte GraphiPrint` dot-matrix printer, challenging Stella's court-appointed guardianship, and mocking Luna's *Kaleidoscope Sun* flute signal, **Luna screams *"GET OUT!"*** Stella backs Luna 100% with her plenary guardianship, sets Delores's overnight bag on the stoop as Leo types `<aac>Go away,</aac>` reclaims the weekend for **a brother, his twin sister, and his older sister**, and packs out Leo's belongings from Portsmouth on Tuesday morning with Grandpa Arthur and a Portsmouth police civil-standby officer—preventing the four-day `$99` Pathfinder bus trip to Kent, Washington and the September 2004 restitution lawsuit.
- **`jack-sarah/paths/after-sibling-weekend-throwout/` (`delores-porter-history`, Jan–Mar 2007 — 22 Parts)**:
  - **Cross-Book Knock-On Effect:** Because Delores in `books/jack-sarah` is the **same Delores** after `books/luna-leo`, Robyn Mahone's passive-aggressive fury at the January 27, 2007 ValueMart freezer-lasagna dinner stems not from Washington state court restitution debt, but from the humiliating Portsmouth police civil-standby and Delores blowing their golden meal ticket with the wealthy Porter family—driving Robyn to target Jack's newly divorced father **Jermaine Bennett** as a replacement meal ticket, while Delores tries to trap Jack via Day-One pregnancy (*The Biological Deadline*) so no sister or guardian can ever scream *"Get out!"* and sever her hold again.
  - **Published Standalone Backstage Dossier:** [`backstage/books/luna-leo/what-if-luna-threw-delores-out-during-sibling-weekend.md`](../../backstage/books/luna-leo/what-if-luna-threw-delores-out-during-sibling-weekend.md)

---

## 4. Saved Brainstorm Notes for Future "What If?" Segments (Unbuilt Ideas)

### A. *Katie & Nathaniel* (`books/katie`) — January 6, 2003 Upstairs Locker Variant
- **Divergence Point:** Monday, January 6, 2003, `8:00 AM` (`Homeroom 212` / Upstairs B-Wing Locker, `e004/c002/p001.md–p002.md`).
- **What If Concept:** Even if Brittany still threw the New Year's Eve party while Uncle David was in Rhode Island, **Heather holds her ground against Tyler's lie**—stubbornly insisting that the pretty blonde girl in the matching `SwiftChair` who locked a bedroom door with Nate WAS his twin sister **Katie** even when Tyler doubles down claiming otherwise (or because Kristen Bauer's varsity-table takedown of Tyler's cousin-Hannah lie happened right before Winter Break). Trust is never broken in January 2003, there is zero five-month silence in Room 204, while shunned Brittany still drifts into Tyler's Smoker's Corner orbit and still needs rescuing in February 2016. *(Also referenced in Section 1 of `what-if-virginia-domicile-unbroken-trust-and-both-rescues.md`.)*

### B. *Rachel: The Anchor in the Storm* (`books/rachel`) — Three Saved "What If?" Concepts
1. **What If #1 — Tom Steps Up & Goes Back to School Like Ellen (`April 21, 2000`, `e006/c001/p002.md` *"The Mirror"*)**:
   - **Divergence Point:** Spring 2000 (`e006/c001/p002.md`), when Ellen's academic growth at `CCHR` holds up an uncomfortable mirror to Tom.
   - **Concept:** Instead of letting insecurity, resentment, and stubbornness poison the household and lead to the 2001 divorce, **Tom decides to be a genuinely good parent and partner**—enrolling at the **Coastal Adult Learning Center** for his `HSE/GED` and continuing to **`CCHR`** just like Ellen to better himself. **The divorce never happens**, keeping **Michael** and **Rachel** (*Bear & Bug*) together in an intact, supportive Virginia Beach home.
2. **What If #2 — Admitting the 1995 Base Camp Non-Separation Evidence Into the Initial Divorce Decree (`2001`, `Va. Code § 20-124.3(4)`)**:
   - **Divergence Point:** Initial 2001 divorce and custody proceedings in Virginia Beach Circuit Court.
   - **Concept:** Entering **Dr. Evans's 1995 clinical trauma and Base Camp file**—documenting why **Michael** and **Rachel** (`Bear & Bug`) are each other's primary neurological and emotional co-regulators and **cannot be split up**—directly into the initial custody evidence under `Va. Code § 20-124.3(4)` (*the child's age and physical and mental condition, giving due consideration to the vital relationship with siblings*).
   - **Two Forking Outcomes:**
     - *Evidence Admitted Into the Initial Decree:* The family-court judge rules from Day One that splitting Michael and Rachel is clinically contraindicated and orders unified custody so Bug & Bear are never separated.
     - *Evidence Excluded or Withheld From the Initial Record:* Leads into the split-custody crisis where Rachel is ordered to go live with Dad.
3. **What If #3 — Michael & Rachel Erupt at Both Parents & Try to Run Away to Jessica's Dorm in Washington State (`September 2001`)**:
   - **Divergence Point:** September 2001, the moment 14-year-old **Rachel** is ordered to go live with Tom, splitting *Bug & Bear* apart.
   - **Concept:** Sixteen-year-old **Michael** and fourteen-year-old **Rachel** erupt in raw fury at **both** parents (Tom and Ellen) and bolt together toward Washington state intending to move in with their twenty-year-old older sister **Jessica** in Seattle so nobody can split Bug & Bear up.
   - **The Realistic Dormitory Catch-22:** Desperate sixteen-year-old Michael and fourteen-year-old Rachel don't care about university rules—they just want their big sister and each other. However, twenty-year-old **Jessica** is living in a **campus dormitory** (`Room 114` at **North Pacific University [`NPU`]** in Seattle) and knows the hard legal and residential reality: university residence-hall rules strictly prohibit housing unemancipated minors overnight, and taking in two minor siblings across state lines against a Virginia custody decree would get Jessica expelled from `NPU` and trigger interstate runaway/custody police intervention—forcing Jessica to protect Michael and Rachel's bond through emergency legal advocacy rather than hiding them in a dorm room.
