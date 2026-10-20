# Fix Status and Regression Evidence

## Confirmations Needed from Craig

Please confirm these pricing decisions before treating the pricing work as final:

- **D1 - Legacy benchmark anchors:** confirm the agreed small-team, mid-market, and enterprise annual benchmark values and their applicable user anchors. The implementation currently preserves $10,000, $16,700, and $69,100, interpolated at 5, 15, and 50 users.
- **D2 - Enterprise pricing above 40 seats:** provide or confirm the annual additional-seat rate. Until then, Enterprise is shown as Contact Us for teams above 40 while Casual and Professional remain priced up to the configured maximum.
- **D3 - Competitor tool costs:** approve replacement values, or explicitly approve retaining current values, for each Appendix A row. Each approved value needs a source URL and date checked before loading into the configuration.

## Summary

FIX-01 through FIX-09 have been implemented: invalid user counts are rejected, pricing and upgrade recommendations follow the configured plan costs, legacy benchmarks are interpolated, Stage 1 and Stage 2 use separate savings baselines, teams above 40 retain self-serve pricing where applicable, and regression testing no longer creates submissions.

Final benchmark values, Enterprise pricing above 40 seats, and competitor tool costs still need Craig's D1-D3 confirmation before pricing is considered final.

## Fix Status

### FIX-01 - Validate user count

Server validation rejects missing, non-numeric, fractional, zero, negative, and unsafe-magnitude user counts with HTTP 422 before any submission save. The configured self-serve maximum defaults to 500; above it, pricing returns no self-serve estimate and the result routes to Contact Us. The frontend number input uses min 1, step 1, and the configured maximum.

**Verification:** the live Stage 1 sweep covered users 0 through 60 at all four frequency values for three passes. The zero-user requests returned 422. Additional requests for -5, 2.5, 1e20, `twelve`, and blank also returned 422. A 501-user request returned Networked, no monthly estimate, and submission ID 0.

### FIX-02 - Cheapest eligible upgrade

Upgrade offers are selected from priced plans above the recommendation and sorted by price. For the current methodology, Enterprise can be offered from 37 users when it is the cheapest higher plan, while the calculated 39-user crossover controls Enterprise recommendation eligibility and the reported switch trigger.

**Verification:** live responses at 37 and 38 users / Most days offered Enterprise at $2,879.17. At 39 and 40 users / Most days, Enterprise was recommended at $2,879.17. The 12-user / Most days baseline remained Professional at $862.50.

### FIX-03 - Separate Stage 1 and Stage 2 savings baselines

Stage 1 strips `current_monthly_software_cost`, `annual_software_savings`, and `roi_percent` from its response and stored calculation snapshot. Stage 2 uses selected competitor tools as its software-spend baseline; with no tools selected, software savings and ROI are unavailable rather than zero-valued claims. No $1,000-per-user fallback remains in the Stage 2 report path.

**Verification:** all 720 valid Stage 1 results in the live sweep were checked for those fields. Four Stage 2 no-tool cases, one for each spend bracket, returned zero selected-tool spend and null monthly software savings, annual software savings, and ROI.

### FIX-04 - Price teams above 40 seats

For teams above 40 and within the configured maximum, the service continues calculating Casual and Professional. Enterprise remains Contact Us while no additional-seat rate is configured; this is the agreed interim behavior. Once D2 provides an Enterprise extra-seat rate, that plan can be priced and compared.

**Verification:** 41 users / Occasionally returned Casual at $820.00, Professional at $3,279.17, and Enterprise Contact Us. 60 users / Occasionally returned Casual at $1,200.00.

### FIX-05 - Smooth legacy benchmark

The legacy benchmark is linearly interpolated between the configured anchors instead of using a three-step lookup. The current values remain until D1 is confirmed. The anchors and their source/date fields are editable in the Methodology configuration.

**Verification:** for the live sweep, same-plan adjacent-user savings changed by at most $1,497.15, below the $5,000 limit. No case in the sweep produced exactly 50% savings.

### FIX-06 - Editable pricing methodology

Runtime calculations read rates, plan prices, thresholds, user maximum, legacy benchmark anchors, and competitor costs from the WordPress Methodology store. Benchmark and competitor rows contain value, source URL, and date checked; configuration validation rejects missing or invalid source details. Initial seed defaults remain in code so a fresh installation can initialize the store; they are not separate runtime calculation sources.

**Verification:** the unit suite includes a configured-rate test ($6 per active user day) yielding $1,080 Casual at 12 users / Most days, and checks source/date completeness for default benchmark and competitor entries.

### FIX-07 - Competitor costs

The Methodology store supports editable, sourced, dated values, but the final D3 updates are still pending Craig's decision. The current local questionnaire has 13 priced tool keys, not the 23 selections referenced in the regression request; the remaining visible options are `None/Not sure` choices. No claim is made that the D3 values have been updated or approved.

**Verification:** all 13 currently priced tool keys were tested across all four spend brackets (52 Stage 2 requests). Each returned one selected tool and its configured positive spend. This verifies wiring to configuration, not the commercial correctness of the current values.

### FIX-08 - Show the usage-sensitive Casual comparison

When Professional is recommended, the result includes the Casual monthly price at the selected usage so prospects can compare the flat Professional price against usage-based Casual pricing.

**Verification:** the live 12-user / Every working day response returned Professional at $862.50 and Casual at $1,260.00. The 12-user / Most days Professional result remained $862.50.

### FIX-09 - Test runs do not create submissions

Authorized administrators can enable test mode and select without-email or placeholder-email behavior. Test-mode requests require the configured mode, administrator capability, and a valid REST nonce. Both submission routes skip repository saves; the repository also rejects test-mode writes before opening a transaction. The frontend forces test submission IDs to zero and ignores stale IDs from local storage. Email-trigger tests use reserved `.invalid` recipients.

**Verification:** the live Stage 1/Stage 2 sweep ran with test mode enabled and without email. Every successful test response used submission ID 0. The submission count was 207 before and after. The test-mode setting was restored to off afterward. The client reports deleting the `blahblah@blahblah.com` leads from the live site; that production deletion was not independently verified here. This plugin has no CRM connector, so external CRM deletion cannot be verified from this repository.

### How to Use Test Mode

1. Sign in to WordPress as an administrator with `manage_options` capability.
2. Open **WP Admin → Eponymos → Settings** (`/wp-admin/admin.php?page=eponymos-settings`).
3. Check **Enable test mode for logged-in administrators**. The two mode choices appear underneath it.
4. Choose **Test Mode Without Email Trigger** for questionnaire/regression testing. Choose **Test Mode With Email Trigger** only when intentionally testing notification behavior; it invokes the email flow using reserved `.invalid` placeholder addresses, not real recipients.
5. Click **Save Changes**.
6. While still signed in as an administrator, open the page containing the Eponymos questionnaire and run the test. The questionnaire displays a test-mode banner and uses a placeholder email. Test runs calculate results but are not saved as submissions.
7. When testing is complete, return to **Eponymos → Settings**, uncheck **Enable test mode for logged-in administrators**, and click **Save Changes** again.

Test mode is a site-wide setting for logged-in administrators, not a per-questionnaire or per-user toggle. Ordinary visitors do not receive administrator test mode, but any administrator using the questionnaire while it is enabled will run a test and create no submission. Keep it disabled during normal administrator use.

## Regression Results

- **Stage 1 matrix:** 737 requests; 720 valid user/frequency cases and 17 expected 422 rejections. All passed.
- **Determinism:** 480 repeated-result comparisons passed.
- **Stage 1 field checks:** 720 valid responses contained none of the three prohibited software-baseline/ROI fields.
- **Savings continuity:** maximum same-plan adjacent-user change was $1,497.15; no exact 50% case occurred.
- **Stage 2 no-tool cases:** four spend brackets passed with null software savings and ROI.
- **Stage 2 tool cases:** 52 requests passed for 13 priced tool keys across four brackets.
- **PHPUnit:** 58 tests and 602 assertions passed.
- **Submissions:** local count remained 207 through the live sweep.

## Open Items

- Craig's D1 benchmark anchors, D2 Enterprise extra-seat rate, and D3 competitor-cost decisions remain to be confirmed.
- The local questionnaire has 13 priced tool keys; reconcile that with the requested 23 selections before declaring the full Appendix A matrix complete.
- Production lead deletion was reported by the client but not verified here; external CRM deletion is outside this plugin's code paths.

Any confusion or corrections to the decision requests, figures, or test scope? Please clarify before the remaining pricing decisions are finalized.
